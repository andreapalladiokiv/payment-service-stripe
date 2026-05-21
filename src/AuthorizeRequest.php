<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\Stripe\Concern\ExtractsCardChecks;
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
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;

final class AuthorizeRequest extends AbstractRequest implements PaymentInstrumentVisitor
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
            ?? throw new RuntimeException("No Stripe reference found for token {$token->id}.");

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

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new \RuntimeException('Gateway does not support hosted-payment instruments.');
    }
}
