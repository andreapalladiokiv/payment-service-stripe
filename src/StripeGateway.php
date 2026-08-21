<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\AbstractRequest;
use Override;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\RegistersCustomers;
use Techork\PaymentService\Gateway\Contract\ResolvesGatewayCustomers;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

final class StripeGateway extends AbstractGateway implements Gateway, RegistersCustomers, ResolvesGatewayCustomers
{
    private ?GatewayCustomerRepository $gatewayCustomerRepository = null;

    #[Override]
    public function getName(): string
    {
        return 'stripe';
    }

    #[Override]
    public function setGatewayCustomerRepository(GatewayCustomerRepository $repository): void
    {
        $this->gatewayCustomerRepository = $repository;
    }

    #[Override]
    public function getDefaultParameters(): array
    {
        return ['apiKey' => ''];
    }

    public function getApiKey(): string
    {
        return $this->getParameter('apiKey') ?? '';
    }

    public function setApiKey(string $value): static
    {
        return $this->setParameter('apiKey', $value);
    }

    #[Override]
    public function createCustomer(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(CreateCustomerRequest::class, $parameters);
    }

    public function updateCustomer(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(UpdateCustomerRequest::class, $parameters);
    }

    /**
     * Where this deployment hosts the page that conducts a Stripe-side authentication.
     *
     * Configuration, not a payment parameter: it is a property of the deployment the way a
     * webhook address is, the same for every payment, and it has no business in a
     * gateway-agnostic `authorize()` signature. It arrives through the credential —
     * {@see \Techork\PaymentService\Gateway\GatewayFactory} hands
     * `$credential->getCredentials()` to `initialize()`, and omnipay merges gateway
     * parameters into every request it builds.
     *
     * Needed because Stripe is the one gateway that does not answer a 3DS card with an
     * address. ConnexPay returns `redirectUrl` and Nuvei returns `acsUrl`; Stripe answers
     * `use_stripe_sdk`, which means "run our JavaScript" and has no address at all. So one
     * is minted here, pointing at a page that loads Stripe.js — and the challenge comes
     * back the same shape as every other gateway's.
     *
     * A setter is what makes it arrive: omnipay applies a credential key only when a
     * matching `set…()` exists ({@see \Omnipay\Common\Helper::initialize}), and drops it
     * in silence otherwise.
     */
    public function setAuthenticationUrl(?string $value): self
    {
        return $this->setParameter('authenticationUrl', $value);
    }

    public function getAuthenticationUrl(): ?string
    {
        $url = $this->getParameter('authenticationUrl');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Where Stripe brings the cardholder back after an authentication IT hosts.
     *
     * Configuration for the same reason, and the alternative to the page above: given one,
     * Stripe answers `redirect_to_url` and hosts the challenge itself. Leaving it unset is
     * the ordinary case — omnipay's `AbstractRequest` already carries the parameter down,
     * so only this gateway-level setter is needed for a credential to name it.
     */
    public function setReturnUrl(?string $value): self
    {
        return $this->setParameter('returnUrl', $value);
    }

    public function createCard(array $options = []): AbstractRequest
    {
        return $this->createRequest(CreateCardRequest::class, $options);
    }

    #[Override]
    public function createPaymentMethod(array $options = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $options['gateway'] ?? null,
            $options['customerId'] ?? null,
        );
        if ($customerReference !== null) {
            $options['customerReference'] = $customerReference;
        }

        return $this->createRequest(CreatePaymentMethodRequest::class, $options);
    }

    public function purchase(array $options = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $options['gateway'] ?? null,
            $options['customerId'] ?? null,
        );
        if ($customerReference !== null) {
            $options['customerReference'] = $customerReference;
        }

        return $this->createRequest(PurchaseRequest::class, $options);
    }

    public function authorize(array $options = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $options['gateway'] ?? null,
            $options['customerId'] ?? null,
        );
        if ($customerReference !== null) {
            $options['customerReference'] = $customerReference;
        }

        return $this->createRequest(AuthorizeRequest::class, $options);
    }

    public function capture(array $options = []): AbstractRequest
    {
        return $this->createRequest(CaptureRequest::class, $options);
    }

    public function refund(array $options = []): AbstractRequest
    {
        return $this->createRequest(RefundRequest::class, $options);
    }

    #[Override]
    public function retryRefund(array $options = []): AbstractRequest
    {
        // Stripe's Refund API can only return funds along the original
        // PaymentIntent — there is no public primitive to redirect a
        // refund onto a different card. The closest alternative is
        // Stripe Issuing (separate product, requires onboarding) and is
        // intentionally out of scope here.
        //
        // Deliberately NOT UnsupportedOperation, unlike the card methods below.
        // {@see \Techork\PaymentService\Gateway\Exception\UnsupportedOperation} asks whether a
        // caller here means a missing primitive for something the gateway otherwise supports,
        // or a misroute. Stripe refunds fine; only redirecting one onto another card is absent,
        // and PaymentGatewayRouter::refund relies on that falling through its catch as a failed
        // GatewayResult so the aggregate records RefundFailed and the saga carries on. Marking
        // it would rethrow instead and break step 2 of that method.
        throw new RuntimeException(
            'Stripe does not support refunding to an alternative card; '
            .'the refund must return to the original payment source.',
        );
    }

    #[Override]
    public function void(array $options = []): AbstractRequest
    {
        return $this->createRequest(VoidRequest::class, $options);
    }

    #[Override]
    public function issueVirtualCard(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'stripe',
            'issueVirtualCard',
            'Stripe Issuing is a separate product and out of scope here; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function updateVirtualCard(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'stripe',
            'updateVirtualCard',
            'Stripe Issuing is a separate product and out of scope here; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function terminateVirtualCard(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'stripe',
            'terminateVirtualCard',
            'Stripe Issuing is a separate product and out of scope here; route card issuing to an issuing gateway.',
        );
    }

    /**
     * Finds the customer reference linked to this instrument, or creates a
     * new Stripe customer and links it. Returns null if customer lookup
     * isn't applicable (no repository, no instrument, no billing address).
     *
     * An empty-string link counts as missing: legacy rows exist where
     * `customer_reference` was written as '', and forwarding that to the
     * requests means they silently drop the `customer` param — Stripe then
     * rejects the charge with "Please include the customer".
     */
    /**
     * The reference this gateway knows one of our customers under, and nothing more.
     *
     * **Lookup only, and there is no creating variant.** Bringing a customer into existence at a
     * provider is its own operation now — {@see \Techork\PaymentService\Gateway\Contract\PaymentGatewayInterface::registerCustomer()},
     * driven by whoever holds the customer. It used to be a lookup-or-create hidden here, which
     * meant saving a card could mint a provider-side customer as a side effect, and taking a
     * payment could mint one that cannot possibly own the instrument being charged: an attached
     * instrument belongs to the customer it was attached to, so a customer created now is a stray
     * one and the charge fails anyway.
     *
     * A miss therefore means no customer on this request, which is the same shape as a caller
     * naming none — and on registration it surfaces as a refusal rather than as an invented person.
     */
    private function resolveCustomerReference(
        ?GatewayCredential $gateway,
        ?string $customerId = null,
    ): ?string {
        if ($gateway === null || $customerId === null || $this->gatewayCustomerRepository === null) {
            return null;
        }

        return $this->gatewayCustomerRepository->find($gateway->getId(), $customerId);
    }
}
