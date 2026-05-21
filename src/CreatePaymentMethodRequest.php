<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Omnipay\Common\Message\AbstractRequest;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Stripe\Concern\ExtractsCardChecks;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;

final class CreatePaymentMethodRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ExtractsCardChecks;
    use InstrumentParameters;
    use StripeRequestParameters;

    public function getData(): array
    {
        $this->validate('instrument', 'gateway');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        return [
            'payment_method_data' => $instrument->accept($this),
            'customerReference' => $this->getCustomerReference(),
        ];
    }

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
            'type' => 'card',
            'card' => ['token' => $reference],
        ];
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('Cannot create a Stripe PaymentMethod from an existing PaymentMethod.');
    }

    public function sendData($data): CreatePaymentMethodResponse
    {
        try {
            $stripe = new StripeClient($this->getApiKey());

            $params = [
                'confirm' => true,
                'usage' => 'off_session',
                'payment_method_data' => $data['payment_method_data'],
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
                'expand' => ['payment_method'],
            ];

            if ($data['customerReference'] !== '') {
                $params['customer'] = $data['customerReference'];
            }

            $setupIntent = $stripe->setupIntents->create($params, $this->stripeOpts());

            if ($setupIntent->status !== 'succeeded') {
                return new CreatePaymentMethodResponse($this, [
                    'reference' => null,
                    'error' => "Setup Intent status: {$setupIntent->status}",
                ]);
            }

            $paymentMethod = $setupIntent->payment_method instanceof \Stripe\PaymentMethod
                ? $setupIntent->payment_method
                : null;

            return new CreatePaymentMethodResponse($this, [
                'reference' => $paymentMethod?->id ?? $setupIntent->payment_method,
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

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new \RuntimeException('Gateway does not support hosted-payment instruments.');
    }
}
