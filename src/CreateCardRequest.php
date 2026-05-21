<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
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

final class CreateCardRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use InstrumentParameters;
    use StripeRequestParameters;

    public function getData(): array
    {
        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        return $instrument->accept($this);
    }

    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->getDecrypter();

        $data = [
            'card' => [
                'number' => $card->number->getNumber($decrypter),
                'exp_month' => $card->expiration->format('m'),
                'exp_year' => $card->expiration->format('Y'),
            ],
        ];

        $cvv = $card->cvc->getCvc($decrypter);
        if ($cvv !== null && $cvv !== '') {
            $data['card']['cvc'] = $cvv;
        }

        $name = (string) $card->holder;
        if ($name !== '') {
            $data['card']['name'] = $name;
        }

        return $data;
    }

    public function visitCash(Cash $cash): mixed
    {
        throw new RuntimeException('Stripe does not support cash payments.');
    }

    public function visitToken(Token $token): never
    {
        throw new RuntimeException('Token does not support tokenization.');
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod does not support tokenization.');
    }

    public function sendData($data): CreateCardResponse
    {
        try {
            $stripe = new StripeClient($this->getApiKey());

            $token = $stripe->tokens->create($data, $this->stripeOpts());

            return new CreateCardResponse($this, [
                'reference' => $token->id,
                'error' => null,
            ]);
        } catch (ApiErrorException $e) {
            return new CreateCardResponse($this, [
                'reference' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new \RuntimeException('Gateway does not support hosted-payment instruments.');
    }
}
