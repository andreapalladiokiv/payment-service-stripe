<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Override;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Common\Contract\CustomerIdentifier;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
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
use Techork\PaymentService\Gateway\Command\RegisterCustomerCommand;
use Techork\PaymentService\Gateway\Exception\RegistrationNeedsCustomer;
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

    #[Override]
    public function getName(): string
    {
        return 'stripe';
    }

    #[Override]
    public function configure(GatewayInfrastructure $infrastructure): void
    {
        $this->infrastructure = $infrastructure;
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
     * Mints a Stripe Customer from an identity. The application's own entry point, kept because
     * the host has reasons to create one that are not this gateway's business; the routed
     * operation is {@see registerCustomer()}, which also remembers the reference.
     *
     * It used to take a bare email and an address, and it used to be reached from resolution on
     * every payment. Both are why a charge could invent a person.
     */
    public function createCustomer(?CustomerIdentity $identity = null, ?BillingAddress $billingAddress = null): GatewayResult
    {
        return new CreateCustomer($this->settings(), $identity, $billingAddress)->create();
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
            $this->resolveCustomerReference($command->customerId, $command->instrument),
        )->tokenize();
    }

    /**
     * Storing an instrument for later use is storing it for somebody, so unlike `tokenize()` this
     * refuses an unnamed customer. Stripe's own rule, not a preference: a PaymentMethod with no
     * Customer is spent by the first confirm and refused forever after, so a registration with
     * nobody to attach it to records a `pm_xxx` that can never be charged. It used to invent one
     * out of the billing address instead.
     */
    #[Override]
    public function registerPaymentMethod(VaultCommand $command): RegistrationResult
    {
        $command->customerId ?? throw RegistrationNeedsCustomer::forGateway('stripe');

        return new RegisterPaymentMethod(
            $this->infrastructure(),
            $this->settings(),
            $command,
            $this->resolveCustomerReference($command->customerId, $command->instrument),
        )->register();
    }

    #[Override]
    public function charge(PlacementCommand $command): AuthorizationResult
    {
        return new Charge(
            $this->infrastructure(),
            $this->settings(),
            $command,
            $this->resolveCustomerReference($command->customerId, $command->instrument),
        )->charge();
    }

    #[Override]
    public function authorize(PlacementCommand $command): AuthorizationResult
    {
        return new Authorize(
            $this->infrastructure(),
            $this->settings(),
            $command,
            $this->resolveCustomerReference($command->customerId, $command->instrument),
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
            $this->resolveCustomerReference($command->customerId, $command->instrument),
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

    /**
     * Hold a Customer for one of ours, from the identity and nothing else.
     *
     * Stripe qualifies for this role because `customers.create` requires no field at all — the
     * email gate that used to gate it was removable for that reason — so an identity is enough
     * and no card has to exist first.
     *
     * The reference is remembered here rather than handed back for the caller to store, because
     * the same map is what {@see resolveCustomerReference()} reads on the next payment: a create
     * that answered and was not recorded would be a second Stripe Customer on the next call.
     */
    #[Override]
    public function registerCustomer(RegisterCustomerCommand $command): RegistrationResult
    {
        $created = $this->createCustomer($command->identity, $command->billingAddress);

        if (! $created->success || $created->reference === null) {
            return RegistrationResult::failed($created->message ?? 'Stripe createCustomer failed');
        }

        $this->infrastructure()->customers->saveReference(
            $this->infrastructure()->credential->getId(),
            $command->customerId,
            $created->reference,
        );

        // Both slots carry it: `reference` because that is what every result's caller reads as
        // "what did this operation produce", and `customerReference` because that is the slot a
        // registration already uses to say which customer the provider linked — and here they are
        // the same `cus_...`.
        return RegistrationResult::succeeded($created->reference)->withCustomerReference($created->reference);
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
     * Which Stripe Customer this payment belongs to: one lookup, keyed on our customer.
     *
     * What it replaced was forty lines whose only job was to recover an identity nobody had ever
     * assigned — look the customer up by *instrument*, failing that retrieve the PaymentMethod
     * from Stripe and adopt whoever owned it, failing that build a Customer out of whatever
     * `BillingAddress` had ridden along with the payment. The last branch is the one that mattered
     * most and it is gone: `resolveCustomerReference()` was lookup-**or-create** and hung on
     * `charge` and `authorize` as well as on the registration, so taking a payment could mint a
     * Customer that cannot possibly own the instrument being charged. An attached PaymentMethod
     * belongs to the Customer it was attached to, so Stripe refuses the pair — leaving behind a
     * stray customer and a failed payment.
     *
     * Creating one is {@see registerCustomer()} now, performed deliberately by whoever holds the
     * customer. A miss here means no `customer` on this request, which for a one-off charge is
     * correct.
     *
     * An empty-string reference counts as missing, and the repository already answers null for
     * one: legacy rows exist where `customer_reference` was written as `''`, and forwarding that
     * makes the requests silently drop the `customer` param — Stripe then rejects the charge with
     * "Please include the customer".
     */
    private function resolveCustomerReference(?CustomerIdentifier $customerId, ?PaymentInstrument $instrument): ?string
    {
        if ($customerId === null) {
            return $this->adoptCustomerFromStripe($customerId, $instrument);
        }

        $gatewayId = $this->infrastructure()->credential->getId();
        $existing = $this->infrastructure()->customers->find($gatewayId, $customerId);

        // `''` is checked here as well as normalised in the Eloquent implementation, because the
        // contract permits any implementation and this is the value that costs a payment: an
        // empty `customer` param makes Stripe reject an attached PaymentMethod with "Please
        // include the customer". Missing, so the repair path gets its turn.
        return $existing !== null && $existing !== ''
            ? $existing
            : $this->adoptCustomerFromStripe($customerId, $instrument);
    }

    /**
     * The repair path, and only that.
     *
     * It used to read as a normal step in resolution, which is what let it look like an answer to
     * "who is this customer" rather than to "our link is missing and Stripe's is not". Stripe is
     * the source of truth for which Customer owns a `pm_xxx`, so there are two cases where asking
     * it is the only thing that can work: a crash between the Stripe attach and our own write,
     * and a PaymentMethod created in Stripe's dashboard that we never attached at all. Minting a
     * fresh Customer in either case makes `paymentIntents.create` fail either way — with no
     * `customer` Stripe rejects an attached PM, and with a different one it rejects the mismatch.
     *
     * The link it repairs is now ours-to-theirs, so it can only be written when we have a
     * customer of our own to write it against; recovered without one, the reference is used for
     * this payment and remembered nowhere.
     */
    private function adoptCustomerFromStripe(?CustomerIdentifier $customerId, ?PaymentInstrument $instrument): ?string
    {
        if (! $instrument instanceof PaymentMethod) {
            return null;
        }

        $gatewayId = $this->infrastructure()->credential->getId();
        $reference = $this->infrastructure()->instruments->find($gatewayId, $instrument);
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

        if ($customerId !== null) {
            $this->infrastructure()->customers->saveReference($gatewayId, $customerId, $customerReference);
        }

        return $customerReference;
    }

    private function settings(): StripeSettings
    {
        return new StripeSettings($this->apiKey, $this->authenticationUrl, $this->returnUrl);
    }

}
