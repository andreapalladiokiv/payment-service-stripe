<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
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
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Stripe\Concern\ExtractsCardChecks;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;

/**
 * Charges via Stripe PaymentIntent.
 * Expects: money (Money), instrument (PaymentInstrument), gateway (Gateway).
 */
final class PurchaseRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ExtractsCardChecks;
    use InstrumentParameters;
    use StripeRequestParameters;

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

    public function visitCash(Cash $cash): never
    {
        throw new RuntimeException('Stripe does not support cash payments.');
    }

    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');
        $reference = $this->getReferenceResolver()->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No Stripe reference found for token {$token->id->toString()}.");

        return [
            'payment_method_data' => ['type' => 'card', 'card' => ['token' => $reference]],
        ];
    }

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

    public function sendData($data): PurchaseResponse
    {
        if (! empty($data['_hosted'])) {
            return $this->sendHostedData($data);
        }

        try {
            $stripe = new StripeClient($this->getApiKey());

            $params = [
                'amount' => $data['amount'],
                'currency' => $data['currency'],
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
                $params['off_session'] = true;
            }

            $threeDS = $this->getThreeDS();
            if ($threeDS !== null) {
                $params['payment_method_options'] = [
                    'card' => [
                        'three_d_secure' => [
                            'cryptogram' => $threeDS->authenticationValue,
                            'transaction_id' => $threeDS->dsTransactionId,
                            'version' => $threeDS->version?->value,
                            'ares_trans_status' => $threeDS->status->value,
                            'electronic_commerce_indicator' => $threeDS->eci?->value,
                        ],
                    ],
                ];
            }

            $paymentIntent = $stripe->paymentIntents->create($params, $this->stripeOpts());

            $challenge = null;
            if ($paymentIntent->status === 'requires_action') {
                $challenge = new ThreeDSChallenge(
                    transactionId: $paymentIntent->id,
                    acsUrl: $paymentIntent->next_action?->redirect_to_url?->url,
                    clientSecret: $paymentIntent->client_secret,
                );
            }

            return new PurchaseResponse($this, [
                'reference' => $paymentIntent->id,
                'challenge' => $challenge,
                'error' => null,
                ...$this->extractStripeChecks($paymentIntent->payment_method instanceof \Stripe\PaymentMethod ? $paymentIntent->payment_method : null),
            ]);
        } catch (ApiErrorException $e) {
            return new PurchaseResponse($this, [
                'reference' => null,
                'challenge' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hosted-payment flow: relay the cardholder to a Stripe-hosted Checkout
     * page rather than charging a supplied instrument inline. Returns a
     * marker payload that {@see sendData()} dispatches to {@see sendHostedData()}.
     */
    public function visitHostedPayment(HostedPayment $hosted): array
    {
        return [
            '_hosted' => true,
            'success_url' => $hosted->successUrl,
            'cancel_url' => $hosted->cancelUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sendHostedData(array $data): PurchaseResponse
    {
        try {
            $stripe = new StripeClient($this->getApiKey());

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

            $session = $stripe->checkout->sessions->create($params, $this->stripeOpts());

            // Use the underlying PaymentIntent ID as the gateway reference so
            // the existing payment_intent.succeeded webhook handler can resolve
            // this PI when Stripe confirms the hosted payment.
            $reference = is_string($session->payment_intent) && $session->payment_intent !== ''
                ? $session->payment_intent
                : (string) $session->id;

            return new PurchaseResponse($this, [
                'reference' => $reference,
                'challenge' => new RedirectChallenge(
                    transactionId: (string) $session->id,
                    url: (string) $session->url,
                    formFields: [],
                ),
                'error' => null,
            ]);
        } catch (ApiErrorException $e) {
            return new PurchaseResponse($this, [
                'reference' => null,
                'challenge' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
