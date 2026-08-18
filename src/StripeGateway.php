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
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

final class StripeGateway extends AbstractGateway implements Gateway
{
    private ?CustomerRepository $customerRepository = null;

    #[Override]
    public function getName(): string
    {
        return 'stripe';
    }

    #[Override]
    public function setCustomerRepository(CustomerRepository $repository): void
    {
        $this->customerRepository = $repository;
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
            $options['instrument'] ?? null,
            $options['billingAddress'] ?? null,
            $options['referenceResolver'] ?? null,
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
            $options['instrument'] ?? null,
            $options['billingAddress'] ?? null,
            $options['referenceResolver'] ?? null,
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
            $options['instrument'] ?? null,
            $options['billingAddress'] ?? null,
            $options['referenceResolver'] ?? null,
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
    private function resolveCustomerReference(
        ?GatewayCredential $gateway,
        ?PaymentInstrument $instrument,
        ?BillingAddress $billingAddress,
        ?GatewayInstrumentRepository $referenceResolver = null,
    ): ?string {
        if ($this->customerRepository === null || $gateway === null || $instrument === null) {
            return null;
        }

        $gatewayId = $gateway->getId();

        $existing = $this->customerRepository->findByInstrument($gatewayId, $instrument);
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $adopted = $this->adoptCustomerFromStripe($gatewayId, $instrument, $referenceResolver);
        if ($adopted !== null) {
            return $adopted;
        }

        // Email is not a precondition here. Stripe's `customers.create` requires no field
        // at all, and {@see CreateCustomerRequest::getData} already filters an absent one
        // out. Gating on it made a missing email — which is optional on our side — decide
        // whether the instrument gets a Customer, and a PaymentMethod without a Customer
        // is single-use: the SetupIntent confirm spends it, and Stripe then refuses it
        // forever with "previously used without being attached to a Customer ... may not
        // be used again". So an address without an email produced a registration that
        // recorded a pm_xxx nobody could ever charge.
        if ($billingAddress === null) {
            return null;
        }

        $response = $this->createCustomer(['billingAddress' => $billingAddress])->send();

        if (! $response->isSuccessful()) {
            throw new RuntimeException("Stripe createCustomer failed: {$response->getMessage()}");
        }

        $customerReference = $response->getTransactionReference()
            ?? throw new RuntimeException('Stripe createCustomer returned no reference.');

        $this->customerRepository->saveAndAttach($gatewayId, $instrument, $customerReference);

        return $customerReference;
    }

    /**
     * Recovers the owning customer for an already-registered PaymentMethod
     * whose local customer link is missing or stale (a crash between the
     * Stripe attach and the local pivot write, or a webhook-created PM).
     * Stripe is the source of truth for which customer owns a pm_xxx, so
     * adopt that owner and repair the local link — minting a fresh customer
     * here would make `paymentIntents.create` fail either way: with no
     * `customer` Stripe rejects an attached PM ("Please include the
     * customer"), and with a different one it rejects the mismatch.
     */
    private function adoptCustomerFromStripe(
        GatewayId $gatewayId,
        PaymentInstrument $instrument,
        ?GatewayInstrumentRepository $referenceResolver,
    ): ?string {
        if (! $instrument instanceof PaymentMethod) {
            return null;
        }

        $reference = $referenceResolver?->find($gatewayId, $instrument);
        if ($reference === null || $reference === '') {
            return null;
        }

        try {
            $paymentMethod = new StripeClient($this->getApiKey())->paymentMethods->retrieve($reference);
        } catch (ApiErrorException) {
            return null;
        }

        $customerReference = is_object($paymentMethod->customer)
            ? $paymentMethod->customer->id ?? ''
            : (string) ($paymentMethod->customer ?? '');

        if ($customerReference === '') {
            return null;
        }

        $this->customerRepository?->saveAndAttach($gatewayId, $instrument, $customerReference);

        return $customerReference;
    }
}
