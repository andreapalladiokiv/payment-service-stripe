<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Omnipay\Common\Message\AbstractRequest;
use Override;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Stripe\Concern\ExtractsCardChecks;
use Techork\PaymentService\Stripe\Concern\FormatsThreeDS;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;

/**
 * @implements PaymentInstrumentVisitor<array>
 */
final class CreatePaymentMethodRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ExtractsCardChecks;
    use FormatsThreeDS;
    use InstrumentParameters;
    use StripeRequestParameters;

    #[Override]
    public function getData(): array
    {
        $this->validate('instrument', 'gateway');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        $paymentMethodData = $instrument->accept($this);

        /** @var ?BillingAddress $billingAddress */
        $billingAddress = $this->getParameter('billingAddress');
        $billingDetails = $this->formatBillingDetails($billingAddress);
        if ($billingDetails !== null && $billingDetails !== []) {
            $paymentMethodData['billing_details'] = $billingDetails;
        }

        return [
            'payment_method_data' => $paymentMethodData,
            'customerReference' => $this->getCustomerReference(),
        ];
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->getDecrypter();

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
        $gateway = $this->getParameter('gateway');
        $reference = $this->getReferenceResolver()->find($gateway->getId(), $token)
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
    public function sendData($data): CreatePaymentMethodResponse
    {
        // Refuse rather than mint an instrument that cannot be reused. Registration
        // promises a PaymentMethod chargeable again later, and in Stripe that requires a
        // Customer: an unattached PM is spent by the SetupIntent confirm below and is
        // rejected on every subsequent use. Creating one anyway would report success and
        // store a pm_xxx that fails at payment time — far from here, and looking like a
        // decline rather than like this.
        if ($data['customerReference'] === '') {
            return new CreatePaymentMethodResponse($this, [
                'reference' => null,
                'error' => 'Stripe registration needs a customer: a PaymentMethod with no customer can only be used once.',
            ]);
        }

        try {
            $stripe = new StripeClient($this->getApiKey());

            // Two endpoints, so two scoped keys — Stripe pins an idempotency key to
            // the endpoint that first used it. See {@see StripeRequestParameters::stripeOpts}.
            $paymentMethod = $stripe->paymentMethods->create($data['payment_method_data'], $this->stripeOpts('payment_method'));

            $stripe->paymentMethods->attach($paymentMethod->id, ['customer' => (string) $data['customerReference']]);

            // Confirm via SetupIntent so Stripe runs AVS/CVC checks against the card and
            // saves it against the customer for off-session reuse. The PM is then
            // re-retrieved to pick up the checks Stripe populates only after confirmation.
            //
            // `requires_action` is acceptable here because the card is already attached —
            // that happened above, before this call — so it is chargeable with the
            // cardholder present, and the 3DS Stripe wanted is re-challenged at the first
            // charge. That last part is only true now that {@see AuthorizeResponse} reads
            // success off the status: a re-challenge used to come back as a completed
            // authorization, which is exactly how a card registered this way went on to be
            // captured without ever having been authorized.
            //
            // What it does not buy is an off-session charge. A merchant-initiated payment
            // on a card whose set-up never completed can still be refused for want of
            // authentication, and `RegistrationResult` has no way to say "usable while the
            // cardholder is here".
            $setupParams = [
                'payment_method' => $paymentMethod->id,
                'customer' => $data['customerReference'],
                'confirm' => true,
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
            ];

            $paymentMethodOptions = $this->formatThreeDS();
            if ($paymentMethodOptions !== null) {
                $setupParams['payment_method_options'] = $paymentMethodOptions;
            }

            $stripe->setupIntents->create($setupParams, $this->stripeOpts('setup_intent'));

            $paymentMethod = $stripe->paymentMethods->retrieve($paymentMethod->id);

            return new CreatePaymentMethodResponse($this, [
                'reference' => $paymentMethod->id,
                'error' => null,
                ...$this->extractStripeChecks($paymentMethod),
            ]);
        } catch (ApiErrorException $e) {
            return new CreatePaymentMethodResponse($this, [
                'reference' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getCustomerReference(): string
    {
        return $this->getParameter('customerReference') ?? '';
    }

    public function setCustomerReference(string $value): self
    {
        return $this->setParameter('customerReference', $value);
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('stripe', 'createPaymentMethod', $hosted);
    }
}
