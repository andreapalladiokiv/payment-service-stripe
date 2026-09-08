<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

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
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Concern\HoldsInfrastructure;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;

final class StripeGateway implements Gateway
{
    use HoldsInfrastructure;

    private string $apiKey = '';

    private ?string $authenticationUrl = null;

    private ?string $returnUrl = null;

    private ?CustomerRepository $customerRepository = null;

    #[Override]
    public function getName(): string
    {
        return 'stripe';
    }

    public function setCustomerRepository(CustomerRepository $repository): void
    {
        $this->customerRepository = $repository;
    }

    #[Override]
    public function configure(GatewayInfrastructure $infrastructure): void
    {
        $this->infrastructure = $infrastructure;
        $this->customerRepository = $infrastructure->customers;
        $this->apiKey = $infrastructure->stringSetting('apiKey');

        // One value per deployment rather than per payment, which is what separates it from
        // anything a command carries: it is where a cardholder comes back to after a step-up.
        $authenticationUrl = $infrastructure->stringSetting('authenticationUrl');
        $this->authenticationUrl = $authenticationUrl === '' ? null : $authenticationUrl;

        $returnUrl = $infrastructure->stringSetting('returnUrl');
        $this->returnUrl = $returnUrl === '' ? null : $returnUrl;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Mints a Stripe Customer. Not a role the contract knows about — nothing routes here — but a
     * PaymentMethod with no Customer is single-use, so {@see resolveCustomerReference()} creates
     * one when an instrument arrives with nobody to belong to.
     */
    public function createCustomer(string $email = '', ?BillingAddress $billingAddress = null): GatewayResult
    {
        return new CreateCustomer($this->settings(), $email, $billingAddress)->create();
    }

    public function updateCustomer(string $customerReference = '', string $email = '', ?BillingAddress $billingAddress = null): GatewayResult
    {
        return new UpdateCustomer($this->settings(), $customerReference, $email, $billingAddress)->update();
    }

    public function getAuthenticationUrl(): ?string
    {
        $url = $this->authenticationUrl;

        return is_string($url) && $url !== '' ? $url : null;
    }

    #[Override]
    public function tokenize(VaultCommand $command): RegistrationResult
    {
        return new Tokenize(
            $this->infrastructure(),
            $this->settings(),
            $command,
            $this->resolveCustomerReference($this->infrastructure()->credential, $command->instrument, $command->billingAddress, $this->infrastructure()->instruments),
        )->tokenize();
    }

    #[Override]
    public function registerPaymentMethod(VaultCommand $command): RegistrationResult
    {
        return new RegisterPaymentMethod(
            $this->infrastructure(),
            $this->settings(),
            $command,
            $this->resolveCustomerReference($this->infrastructure()->credential, $command->instrument, $command->billingAddress, $this->infrastructure()->instruments),
        )->register();
    }

    #[Override]
    public function charge(PlacementCommand $command): AuthorizationResult
    {
        return new Charge(
            $this->infrastructure(),
            $this->settings(),
            $command,
            $this->resolveCustomerReference($this->infrastructure()->credential, $command->instrument, $command->billingAddress, $this->infrastructure()->instruments),
        )->charge();
    }

    #[Override]
    public function authorize(PlacementCommand $command): AuthorizationResult
    {
        return new Authorize(
            $this->infrastructure(),
            $this->settings(),
            $command,
            $this->resolveCustomerReference($this->infrastructure()->credential, $command->instrument, $command->billingAddress, $this->infrastructure()->instruments),
        )->authorize();
    }

    /**
     * The same provider call as {@see authorize()}, with the series position added — which is why
     * the two share an operation class and differ only in what the command puts in it. It is still
     * a separate operation, because whether a payment belongs to a series is the caller's to state
     * and no field of an ordinary authorization implies it.
     */
    #[Override]
    public function authorizeRebilling(RebillingCommand $command): AuthorizationResult
    {
        return new Authorize(
            $this->infrastructure(),
            $this->settings(),
            $command->toPlacement(),
            $this->resolveCustomerReference($this->infrastructure()->credential, $command->instrument, $command->billingAddress, $this->infrastructure()->instruments),
        )->authorize();
    }

    #[Override]
    public function capture(CaptureCommand $command): GatewayResult
    {
        return new Capture($this->settings(), $command)->capture();
    }

    #[Override]
    public function refund(RefundCommand $command): GatewayResult
    {
        return new Refund($this->settings(), $command)->refund();
    }

    #[Override]
    public function retryRefund(RefundCommand $command): GatewayResult
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
        // and the gateway stack::refund relies on that falling through its catch as a failed
        // GatewayResult so the aggregate records RefundFailed and the saga carries on. Marking
        // it would rethrow instead and break step 2 of that method.
        throw new RuntimeException(
            'Stripe does not support refunding to an alternative card; '
            .'the refund must return to the original payment source.',
        );
    }

    #[Override]
    public function cancel(CancelCommand $command): GatewayResult
    {
        return new Cancel($this->settings(), $command)->cancel();
    }

    #[Override]
    public function issueVirtualCard(IssueCardCommand $command): VirtualCardResult
    {
        throw UnsupportedOperation::forGateway(
            'stripe',
            'issueVirtualCard',
            'Stripe Issuing is a separate product and out of scope here; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function updateVirtualCard(UpdateCardCommand $command): VirtualCardResult
    {
        throw UnsupportedOperation::forGateway(
            'stripe',
            'updateVirtualCard',
            'Stripe Issuing is a separate product and out of scope here; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function terminateVirtualCard(TerminateCardCommand $command): GatewayResult
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
        // at all, and {@see CreateCustomer::payload()} already filters an absent one
        // out. Gating on it made a missing email — which is optional on our side — decide
        // whether the instrument gets a Customer, and a PaymentMethod without a Customer
        // is single-use: the SetupIntent confirm spends it, and Stripe then refuses it
        // forever with "previously used without being attached to a Customer ... may not
        // be used again". So an address without an email produced a registration that
        // recorded a pm_xxx nobody could ever charge.
        if ($billingAddress === null) {
            return null;
        }

        $created = $this->createCustomer(billingAddress: $billingAddress);

        if (! $created->success) {
            throw new RuntimeException("Stripe createCustomer failed: {$created->message}");
        }

        $customerReference = $created->reference
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

    private function settings(): StripeSettings
    {
        return new StripeSettings($this->apiKey, $this->authenticationUrl, $this->returnUrl);
    }

}
