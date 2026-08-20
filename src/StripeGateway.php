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
use Techork\PaymentService\Gateway\Contract\CustomerIdentitySource;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\ResolvesGatewayCustomers;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

final class StripeGateway extends AbstractGateway implements Gateway, ResolvesGatewayCustomers
{
    private ?GatewayCustomerRepository $gatewayCustomerRepository = null;

    private ?CustomerIdentitySource $customerIdentitySource = null;

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
    public function setCustomerIdentitySource(CustomerIdentitySource $source): void
    {
        $this->customerIdentitySource = $source;
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
     * Which Stripe Customer one of ours is, creating it the first time.
     *
     * This used to be a search standing in for knowing: find by instrument, else ask Stripe who
     * owns the instrument, else invent a Customer from whatever address rode along with this
     * payment — so the same person paying from two addresses became two Stripe customers, and a
     * payment with no address got no Customer and therefore an unusable PaymentMethod.
     *
     * All of it is gone, and **not replaced by a fallback**. With no customer named there is no
     * customer, and null surfaces as a refused registration
     * ({@see CreatePaymentMethodRequest::sendData()} already refuses without one) rather than as
     * a Stripe Customer named after one payment's billing details. A silent fallback to the old
     * behaviour would have made this change optional, and it would have been taken by every
     * caller that forgot to name the customer.
     */
    private function resolveCustomerReference(
        ?GatewayCredential $gateway,
        ?string $customerId = null,
    ): ?string {
        if ($gateway === null || $customerId === null || $this->gatewayCustomerRepository === null) {
            return null;
        }

        $gatewayId = $gateway->getId();

        return $this->gatewayCustomerRepository->find($gatewayId, $customerId)
            ?? $this->createCustomerFor($gatewayId, $customerId);
    }

    /**
     * Creates the Stripe Customer for one of ours and remembers which is which.
     *
     * Built from the identity the host holds rather than from whatever address rode along with
     * this payment — that is the difference the customer aggregate buys. With no identity source
     * bound there is nothing to build one from, and null is the honest answer: it surfaces as a
     * refused registration rather than as a customer named after one payment's billing details.
     */
    private function createCustomerFor(GatewayId $gatewayId, string $customerId): ?string
    {
        $identity = $this->customerIdentitySource?->find($customerId);

        if ($identity === null) {
            return null;
        }

        $response = $this->createCustomer(['customerIdentity' => $identity])->send();

        if (! $response->isSuccessful()) {
            throw new RuntimeException("Stripe createCustomer failed: {$response->getMessage()}");
        }

        $reference = $response->getTransactionReference()
            ?? throw new RuntimeException('Stripe createCustomer returned no reference.');

        $this->gatewayCustomerRepository?->saveReference($gatewayId, $customerId, $reference);

        return $reference;
    }

}
