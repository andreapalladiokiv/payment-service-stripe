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
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
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
 * Takes a payment outright through a Stripe PaymentIntent — the same call {@see Authorize} makes,
 * without `capture_method: manual`, so the money moves rather than being held.
 *
 * {@see payload()} builds the body and nothing else; {@see charge()} sends it and hands the answer
 * to {@see PaymentIntentOutcome} with `succeeded` as the status that means it did what it
 * promised. A charge parked at anything else with no step-up to present is a payment that went
 * nowhere the caller can act on, and it is refused in words rather than reported as a success
 * because it happens to carry an id.
 *
 * {@see HostedPayment} takes the other branch: there is no instrument to charge, so the operation
 * opens a Stripe Checkout Session instead and returns somewhere to send the buyer. The `_hosted`
 * marker in the payload is what {@see charge()} dispatches on — the two branches build different
 * bodies for different endpoints, and the payload says which.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class Charge implements PaymentInstrumentVisitor
{
    use FormatsThreeDS;
    use StripeRequestParameters;

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

        // Suffix, not the whole descriptor — Stripe rejects `statement_descriptor` on a
        // card PaymentIntent. Same reasoning as {@see Authorize::payload()}.
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
        throw UnsupportedInstrument::forGateway('stripe', 'purchase', $cash);
    }

    #[Override]
    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->infrastructure->credential;
        $reference = $this->infrastructure->instruments->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No Stripe reference found for token {$token->id->toString()}.");

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
        $paymentMethod->isAttached() || throw UnsupportedInstrument::needsAttachedCustomer('stripe', 'charge', $paymentMethod);

        /** @var GatewayCredential $gateway */
        $gateway = $this->infrastructure->credential;
        $reference = $this->infrastructure->instruments->find($gateway->getId(), $paymentMethod)
            ?? throw new RuntimeException("No Stripe reference found for payment method {$paymentMethod->id}.");

        return [
            'payment_method' => $reference,
        ];
    }

    /**
     * Hosted-payment flow: relay the cardholder to a Stripe-hosted Checkout
     * page rather than charging a supplied instrument inline. Returns a
     * marker payload that {@see charge()} dispatches to {@see chargeHosted()}.
     */
    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): array
    {
        return [
            '_hosted' => true,
            'success_url' => $hosted->successUrl,
            'cancel_url' => $hosted->cancelUrl,
        ];
    }

    public function charge(): AuthorizationResult
    {
        $data = $this->payload();

        if (! empty($data['_hosted'])) {
            return $this->chargeHosted($data);
        }

        try {
            $stripe = new StripeClient($this->settings->apiKey);

            $params = [
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'confirm' => true,
                // `never` unless the credential named a return address. Refusing redirects
                // leaves Stripe with only `use_stripe_sdk` to offer a card owing 3DS, which
                // is fine — that shape is presentable too, on the page the credential names
                // in `authenticationUrl`. Configure neither and there is nothing to show.
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => $this->settings->normalizedReturnUrl() === null ? 'never' : 'always'],
                'expand' => ['payment_method', 'latest_charge.balance_transaction'],
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

            // Read from the initiation, not from whether the instrument is stored — the two are
            // different questions and only one of them is what `off_session` means. See the same
            // repair in {@see Authorize::authorize()}.
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

            $paymentIntent = $stripe->paymentIntents->create($params, $this->stripeOpts($this->command->clientUniqueId));

            return new PaymentIntentOutcome()->map(
                $paymentIntent,
                StripeChallenge::from($paymentIntent, $this->settings->normalizedAuthenticationUrl()),
                'succeeded',
            );
        } catch (ApiErrorException $e) {
            return AuthorizationResult::failed($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function chargeHosted(array $data): AuthorizationResult
    {
        try {
            $stripe = new StripeClient($this->settings->apiKey);

            $params = [
                'mode' => 'payment',
                'success_url' => $data['success_url'],
                'cancel_url' => $data['cancel_url'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $data['currency'],
                        'unit_amount' => $data['amount'],
                        'product_data' => ['name' => 'Payment'],
                    ],
                    'quantity' => 1,
                ]],
            ];

            if (isset($data['customer'])) {
                $params['customer'] = $data['customer'];
            }

            // Scoped away from the PaymentIntent branch above: both branches run off the
            // same `clientUniqueId`, and Stripe pins a key to its first endpoint. The two
            // are mutually exclusive today, which makes the collision latent rather than
            // absent. See {@see StripeRequestParameters::stripeOpts}.
            $session = $stripe->checkout->sessions->create($params, $this->stripeOpts('checkout_session'));

            // Use the underlying PaymentIntent ID as the gateway reference so
            // the existing payment_intent.succeeded webhook handler can resolve
            // this PI when Stripe confirms the hosted payment.
            $reference = is_string($session->payment_intent) && $session->payment_intent !== ''
                ? $session->payment_intent
                : (string) $session->id;

            // Not successful and not a failure: the buyer has somewhere to go and nothing has
            // been taken yet. A Session reports no payment-intent status because it is a promise
            // of one, so {@see PaymentIntentOutcome} has nothing to read here — the redirect is
            // the answer, and it is built directly.
            return AuthorizationResult::requiresAction(
                $reference,
                new RedirectChallenge(
                    transactionId: (string) $session->id,
                    url: (string) $session->url,
                    formFields: [],
                ),
            )->withMetadata(['opening_transaction_reference' => $reference]);
        } catch (ApiErrorException $e) {
            return AuthorizationResult::failed($e->getMessage());
        }
    }
}
