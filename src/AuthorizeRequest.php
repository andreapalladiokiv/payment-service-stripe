<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Override;
use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\Stripe\Concern\ExtractsCardChecks;
use Techork\PaymentService\Stripe\Concern\FormatsThreeDS;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * @implements PaymentInstrumentVisitor<array>
 */
final class AuthorizeRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ExtractsCardChecks;
    use FormatsThreeDS;
    use InstrumentParameters;
    use StripeRequestParameters;

    #[Override]
    public function getData(): array
    {
        $this->validate('money', 'instrument', 'gateway');

        /** @var Money $money */
        $money = $this->getParameter('money');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');
        $data = $instrument->accept($this);

        $data['amount'] = (int) $money->getAmount();
        $data['currency'] = strtolower($money->getCurrency()->getCode());

        if ($this->getCustomerReference() !== '') {
            $data['customer'] = $this->getCustomerReference();
        }

        $statementDescription = $this->getStatementDescription();
        if ($statementDescription !== null && $statementDescription !== '') {
            $data['statement_descriptor'] = $statementDescription;
        }

        $description = $this->getDescription();
        if ($description !== null && $description !== '') {
            $data['description'] = $description;
        }

        $billingAddress = $this->getParameter('billingAddress');
        $billingDetails = $this->formatBillingDetails($billingAddress);
        if ($billingDetails !== null && isset($data['payment_method_data'])) {
            $data['payment_method_data']['billing_details'] = $billingDetails;
        }

        return $data;
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
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->getDecrypter();

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
        $gateway = $this->getParameter('gateway');
        $reference = $this->getReferenceResolver()->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No Stripe reference found for token {$token->id}.");

        return [
            'payment_method_data' => ['type' => 'card', 'card' => ['token' => $reference]],
        ];
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');
        $reference = $this->getReferenceResolver()->find($gateway->getId(), $paymentMethod)
            ?? throw new RuntimeException("No Stripe reference found for payment method $paymentMethod->id.");

        return [
            'payment_method' => $reference,
        ];
    }

    #[Override]
    public function sendData($data): AuthorizeResponse
    {
        try {
            $stripe = new StripeClient($this->getApiKey());
            $params = [
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'capture_method' => 'manual',
                'confirm' => true,
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
                'expand' => ['payment_method'],
            ];

            if (isset($data['customer'])) {
                $params['customer'] = $data['customer'];
            }

            if (isset($data['statement_descriptor'])) {
                $params['statement_descriptor'] = $data['statement_descriptor'];
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
            // `initiation` is the fact, it reaches every request, and it was already here.
            if ($this->getInitiation()->isMerchantInitiated()) {
                $params['off_session'] = true;
            }

            $paymentMethodOptions = $this->formatThreeDS();
            if ($paymentMethodOptions !== null) {
                $params['payment_method_options'] = $paymentMethodOptions;
            }

            $paymentIntent = $stripe->paymentIntents->create($params, $this->stripeOpts());

            $challenge = StripeChallenge::from($paymentIntent);

            return new AuthorizeResponse($this, [
                'reference' => $paymentIntent->id,
                'challenge' => $challenge,
                'error' => null,
                ...$this->extractStripeChecks($paymentIntent->payment_method instanceof \Stripe\PaymentMethod ? $paymentIntent->payment_method : null),
            ]);
        } catch (ApiErrorException $e) {
            return new AuthorizeResponse($this, [
                'reference' => null,
                'challenge' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('stripe', 'authorize', $hosted);
    }
}
