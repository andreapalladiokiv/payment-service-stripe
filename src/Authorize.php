<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Money\Money;
use Override;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Challenge\SdkChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\Concern\FormatsThreeDS;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;

/**
 * Reserves funds on a card through a Stripe PaymentIntent created with `capture_method: manual`.
 *
 * Two parts, and only the second one touches the network: {@see payload()} turns the command's
 * instrument into the body, {@see authorize()} sends it and hands the answer to
 * {@see PaymentIntentOutcome}, which knows that an authorization is finished at
 * `requires_capture` and nowhere else. There is no request object with a `getData()`/`sendData()`
 * lifecycle and no response object wrapping a payload array for a shared assembler to re-read —
 * Omnipay needed both because a request was a parameter bag and a response had to be interrogated
 * through a common interface. Neither is true here.
 *
 * The same provider call serves {@see StripeGateway::authorizeRebilling()}: Stripe expresses a
 * series position through `off_session` and the customer the payment method is attached to, so
 * there is no anchor field for a genesis reference to land in and the rebilling command arrives
 * here as an ordinary placement. See {@see \Techork\PaymentService\Gateway\Command\RebillingCommand::toPlacement()}.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class Authorize implements PaymentInstrumentVisitor
{
    use FormatsThreeDS;
    use StripeRequestParameters;

    /**
     * The payload key that says this payment is waiting for its payer's browser rather than
     * carrying an instrument. Its value is where that browser comes back to.
     *
     * A key rather than a second `instanceof` in {@see authorize()}: which shape the body takes is
     * already the visitor's decision everywhere else here, and asking the command again would make
     * it two decisions that have to agree.
     */
    private const string DEFERRED_TO_THE_PAYER = 'deferred_return_url';

    public function __construct(
        private readonly GatewayInfrastructure $infrastructure,
        private readonly StripeSettings $settings,
        private readonly PlacementCommand $command,
        private readonly ?string $customerReference = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        /** @var Money $money */
        $money = $this->command->amount;

        /** @var PaymentInstrument $instrument */
        $instrument = $this->command->instrument;
        $data = $instrument->accept($this);

        $data['amount'] = (int) $money->getAmount();
        $data['currency'] = strtolower($money->getCurrency()->getCode());

        if (($this->customerReference ?? '') !== '') {
            $data['customer'] = ($this->customerReference ?? '');
        }

        // `statement_descriptor_suffix`, not `statement_descriptor`: Stripe refuses the
        // latter on a PaymentIntent whose payment_method_type is `card`, which is every
        // PaymentIntent this operation builds. The account's own prefix supplies the rest
        // of the descriptor, and the two together are capped at 22 characters — a longer
        // suffix is rejected rather than truncated here, because shortening what appears
        // on a cardholder's statement is the caller's decision, not ours.
        $statementDescription = $this->command->statementDescription;
        if ($statementDescription !== null && $statementDescription !== '') {
            $data['statement_descriptor_suffix'] = $statementDescription;
        }

        $description = $this->command->description;
        if ($description !== null && $description !== '') {
            $data['description'] = $description;
        }

        $billingDetails = $this->formatBillingDetails($this->command->customer);
        if ($billingDetails !== null && isset($data['payment_method_data'])) {
            $data['payment_method_data']['billing_details'] = $billingDetails;
        }

        return $data;
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->infrastructure->decrypter;

        return [
            'payment_method_data' => [
                'type' => 'card',
                'card' => array_filter([
                    'number' => $card->number->getNumber($decrypter),
                    'exp_month' => (int) $card->expiration->format('m'),
                    'exp_year' => (int) $card->expiration->format('Y'),
                    'cvc' => $card->cvc->getCvc($decrypter) ?: null,
                ]),
            ],
        ];
    }

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw UnsupportedInstrument::forGateway('stripe', 'authorize', $cash);
    }

    #[Override]
    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->infrastructure->credential;
        $reference = $this->infrastructure->instruments->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No Stripe reference found for token {$token->id}.");

        return [
            'payment_method_data' => ['type' => 'card', 'card' => ['token' => $reference]],
        ];
    }

    /**
     * Refused when nobody has claimed the card, and that is the whole reason this is one
     * method rather than two.
     *
     * There was a `visitAttachedPaymentMethod()` beside a `visitPaymentMethod()` that only
     * threw, which made "payable" something a signature carried. Attached is a state of a
     * payment method — see {@see PaymentMethod::isAttached()} — so the branch became a guard.
     * What it costs is that the guarantee is now checked rather than typed; what it buys is
     * one credential with one identity everywhere downstream of here.
     */
    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        // A stored card is charged to somebody, and an unattached one names nobody. The
        // payer is not derivable from the card: what used to answer was the address the
        // payment method carried, so a card was charged to whoever it was billed to.
        $paymentMethod->isAttached() || throw UnsupportedInstrument::needsAttachedCustomer('stripe', 'authorize', $paymentMethod);

        /** @var GatewayCredential $gateway */
        $gateway = $this->infrastructure->credential;
        $reference = $this->infrastructure->instruments->find($gateway->getId(), $paymentMethod)
            ?? throw new RuntimeException("No Stripe reference found for payment method {$paymentMethod->id}.");

        return [
            'payment_method' => $reference,
        ];
    }

    /**
     * A payment the payer's own browser will complete, and the only arm here that names no
     * instrument at all.
     *
     * Every other arm answers "here is what to charge"; this one answers "there is nothing to
     * charge yet". Stripe's Express Checkout Element collects the wallet on the caller's page and
     * confirms the payment itself, against the intent's client secret — so what this operation has
     * to produce is an intent that exists, holds the amount open and is waiting for exactly that.
     *
     * The return url comes off the instrument rather than off the account's settings, because it
     * belongs to this payment: the payer is on one checkout's page and that is the page they come
     * back to. The account-wide one in {@see StripeSettings} stays what it is — a default for the
     * flows that have no page of their own.
     *
     * @return array<string, string>
     */
    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): array
    {
        return [self::DEFERRED_TO_THE_PAYER => $hosted->successUrl];
    }

    public function authorize(): AuthorizationResult
    {
        $data = $this->payload();

        try {
            $stripe = new StripeClient($this->settings->apiKey);

            // Derived once and shared by both branches, which call the same endpoint and are
            // mutually exclusive. Two `stripeOpts()` calls in one operation are what
            // `StripeRequestParametersTest` refuses structurally, and it is right to: a key bound
            // to the wrong endpoint is permanent, because the id is stable.
            $opts = $this->stripeOpts($this->command->clientUniqueId);

            if (isset($data[self::DEFERRED_TO_THE_PAYER])) {
                return $this->deferToThePayer($stripe, $data, $opts);
            }

            $params = [
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'capture_method' => 'manual',
                'confirm' => true,
                // `never` unless the credential named a return address. Refusing redirects
                // leaves Stripe with only `use_stripe_sdk` to offer a card owing 3DS, which
                // is fine — that shape is presentable too, on the page the credential names
                // in `authenticationUrl`. Configure neither and there is nothing to show.
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => $this->settings->normalizedReturnUrl() === null ? 'never' : 'always'],
                'expand' => ['payment_method'],
            ];

            if (isset($data['customer'])) {
                $params['customer'] = $data['customer'];
            }

            if (isset($data['statement_descriptor_suffix'])) {
                $params['statement_descriptor_suffix'] = $data['statement_descriptor_suffix'];
            }

            if (isset($data['description'])) {
                $params['description'] = $data['description'];
            }

            if (isset($data['payment_method_data'])) {
                $params['payment_method_data'] = $data['payment_method_data'];
            } else {
                $params['payment_method'] = $data['payment_method'];
            }

            // `off_session` says the cardholder is not there to answer for this payment, and it
            // used to be set from the wrong fact: whether the instrument was a stored reference
            // rather than a raw card. Those are different questions. Paying with a saved card in
            // a live checkout is a cardholder-initiated payment, and declaring it off-session
            // tells the network the opposite — which is the same misdeclaration in reverse that
            // an unmarked recurring charge makes, and it costs the same thing: an authentication
            // exemption claimed where none applies, and a chargeback right the merchant thought
            // it had.
            //
            // `initiation` is the fact, it reaches every operation, and it was already here.
            if ($this->command->initiation->isMerchantInitiated()) {
                $params['off_session'] = true;
            }

            $paymentMethodOptions = $this->formatThreeDS($this->command->threeDS);
            if ($paymentMethodOptions !== null) {
                $params['payment_method_options'] = $paymentMethodOptions;
            }

            $returnUrl = $this->settings->normalizedReturnUrl();
            if ($returnUrl !== null) {
                $params['return_url'] = $returnUrl;
            }

            $paymentIntent = $stripe->paymentIntents->create($params, $opts);

            return new PaymentIntentOutcome()->map(
                $paymentIntent,
                StripeChallenge::from($paymentIntent, $this->settings->normalizedAuthenticationUrl()),
                'requires_capture',
            );
        } catch (ApiErrorException $e) {
            return AuthorizationResult::failed($e->getMessage());
        }
    }

    /**
     * Opens the payment and stops, leaving it for the payer's browser to confirm.
     *
     * `confirm` is absent rather than false, which is the whole difference from the path above: an
     * intent created without it sits at `requires_payment_method`, holding the amount and the
     * account's payment-method configuration, and the first thing to touch it is Stripe.js in the
     * payer's browser.
     *
     * Which is why the answer is assembled here instead of going through
     * {@see PaymentIntentOutcome}. That mapping reads a status against the one that means done —
     * `requires_capture` for an authorization — and calls everything else an outcome nobody can act
     * on. Here the status nobody can act on is exactly the expected one, and the payment is
     * `requires_action` by construction: nothing has been asked of an acquirer yet. Nuvei's hosted
     * flow arrives at the same shape from the same reasoning
     * ({@see \Techork\PaymentService\Nuvei\Purchase}).
     *
     * The challenge is an {@see SdkChallenge} because that is what the payer's page will do — run
     * the provider's JavaScript over this payment — and it carries a reference rather than the
     * client secret, for the reason that class spells out: a challenge is written into an
     * append-only stream, and whoever holds the secret can confirm the payment. The caller fetches
     * the secret itself, server-side, for the one browser about to use it.
     *
     * `allow_redirects` is `always` here where the instrument path leaves it conditional: this
     * operation has a return url by construction — the instrument carries one — so there is
     * nowhere for a redirect-based method to strand the payer.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $opts
     *
     * @throws ApiErrorException handled by the caller, which turns it into a failed result.
     */
    private function deferToThePayer(StripeClient $stripe, array $data, array $opts): AuthorizationResult
    {
        $params = [
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'capture_method' => 'manual',
            'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'always'],
            'return_url' => $data[self::DEFERRED_TO_THE_PAYER],
        ];

        foreach (['customer', 'statement_descriptor_suffix', 'description'] as $optional) {
            if (isset($data[$optional])) {
                $params[$optional] = $data[$optional];
            }
        }

        $paymentIntent = $stripe->paymentIntents->create($params, $opts);

        return AuthorizationResult::requiresAction(
            $paymentIntent->id,
            new SdkChallenge(
                authenticationId: $paymentIntent->id,
                paymentReference: $paymentIntent->id,
            ),
        )->withMetadata(['opening_transaction_reference' => $paymentIntent->id]);
    }
}
