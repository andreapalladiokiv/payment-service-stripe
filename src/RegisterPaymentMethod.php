<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Override;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\Concern\ExtractsCardChecks;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;

/**
 * Stores a card with Stripe as a reusable PaymentMethod attached to a Customer.
 *
 * Three provider calls, not one: create the PaymentMethod, attach it to the customer, then
 * confirm a SetupIntent so Stripe runs its checks and saves the card for off-session reuse.
 * {@see payload()} builds only the first of them — the part that depends on the instrument — and
 * {@see register()} makes the sequence and answers with what came back.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class RegisterPaymentMethod implements PaymentInstrumentVisitor
{
    use ExtractsCardChecks;
    use StripeRequestParameters;

    public function __construct(
        private readonly GatewayInfrastructure $infrastructure,
        private readonly StripeSettings $settings,
        private readonly VaultCommand $command,
        private readonly ?string $customerReference = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        /** @var PaymentInstrument $instrument */
        $instrument = $this->command->instrument;

        $paymentMethodData = $instrument->accept($this);

        /** @var ?BillingAddress $billingAddress */
        $billingAddress = $this->command->billingAddress;
        $billingDetails = $this->formatBillingDetails($billingAddress);
        if ($billingDetails !== null && $billingDetails !== []) {
            $paymentMethodData['billing_details'] = $billingDetails;
        }

        return [
            'payment_method_data' => $paymentMethodData,
            'customerReference' => ($this->customerReference ?? ''),
        ];
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->infrastructure->decrypter;

        return [
            'type' => 'card',
            'card' => array_filter([
                'number' => $card->number->getNumber($decrypter),
                'exp_month' => (int) $card->expiration->format('m'),
                'exp_year' => (int) $card->expiration->format('Y'),
                'cvc' => $card->cvc->getCvc($decrypter) ?: null,
            ]),
        ];
    }

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw new RuntimeException('Stripe does not support cash payments.');
    }

    #[Override]
    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->infrastructure->credential;
        $reference = $this->infrastructure->instruments->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No Stripe reference found for token {$token->id}.");

        return [
            'type' => 'card',
            'card' => ['token' => $reference],
        ];
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('Cannot create a Stripe PaymentMethod from an existing PaymentMethod.');
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('stripe', 'createPaymentMethod', $hosted);
    }

    public function register(): RegistrationResult
    {
        $data = $this->payload();

        // Refuse rather than mint an instrument that cannot be reused. Registration
        // promises a PaymentMethod chargeable again later, and in Stripe that requires a
        // Customer: an unattached PM is spent by the SetupIntent confirm below and is
        // rejected on every subsequent use. Creating one anyway would report success and
        // store a pm_xxx that fails at payment time — far from here, and looking like a
        // decline rather than like this.
        if ($data['customerReference'] === '') {
            return RegistrationResult::failed(
                'Stripe registration needs a customer: a PaymentMethod with no customer can only be used once.',
            );
        }

        try {
            $stripe = new StripeClient($this->settings->apiKey);

            // Two endpoints, so two scoped keys — Stripe pins an idempotency key to
            // the endpoint that first used it. See {@see StripeRequestParameters::stripeOpts}.
            $paymentMethod = $stripe->paymentMethods->create($data['payment_method_data'], $this->stripeOpts($this->command->clientUniqueId, 'payment_method'));

            $stripe->paymentMethods->attach($paymentMethod->id, ['customer' => (string) $data['customerReference']]);

            // Confirm via SetupIntent so Stripe runs AVS/CVC checks against the card and
            // saves it against the customer for off-session reuse. The PM is then
            // re-retrieved to pick up the checks Stripe populates only after confirmation.
            //
            // `requires_action` is acceptable here because the card is already attached —
            // that happened above, before this call — so it is chargeable with the
            // cardholder present, and the 3DS Stripe wanted is re-challenged at the first
            // charge. That last part is only true now that {@see PaymentIntentOutcome} reads
            // success off the status: a re-challenge used to come back as a completed
            // authorization, which is exactly how a card registered this way went on to be
            // captured without ever having been authorized.
            //
            // What it does not buy is an off-session charge. A merchant-initiated payment
            // on a card whose set-up never completed can still be refused for want of
            // authentication, and `RegistrationResult` has no way to say "usable while the
            // cardholder is here".
            //
            // No `payment_method_options` block: the SetupIntent used to be given one built from
            // an authentication read off the parameter bag, and the bag was never filled on this
            // path — a {@see VaultCommand} carries no attestation, because vaulting a card is not
            // a payment and there is nothing for an issuer to have authenticated. The block was
            // unreachable, and it is left out rather than written as an unconditional null.
            $setupParams = [
                'payment_method' => $paymentMethod->id,
                'customer' => $data['customerReference'],
                'confirm' => true,
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
            ];

            $stripe->setupIntents->create($setupParams, $this->stripeOpts($this->command->clientUniqueId, 'setup_intent'));

            $paymentMethod = $stripe->paymentMethods->retrieve($paymentMethod->id);

            $checks = $this->extractStripeChecks($paymentMethod);

            return RegistrationResult::succeeded($paymentMethod->id)->withChecks(
                self::check($checks['address_line_check']),
                self::check($checks['postal_code_check']),
                self::check($checks['cvc_check']),
            );
        } catch (ApiErrorException $e) {
            return RegistrationResult::failed($e->getMessage());
        }
    }

    private static function check(?string $raw): ?CheckResult
    {
        return $raw === null ? null : CheckResult::from($raw);
    }
}
