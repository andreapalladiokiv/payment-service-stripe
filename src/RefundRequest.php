<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Refunds a Stripe PaymentIntent (pi_xxx).
 * Expects: money (Money), transactionReference (pi_xxx).
 */
final class RefundRequest extends AbstractRequest
{
    use StripeRequestParameters;

    public function getData(): array
    {
        $this->validate('money', 'transactionReference');

        /** @var Money $money */
        $money = $this->getParameter('money');

        return [
            'amount' => (int) $money->getAmount(),
            'payment_intent' => $this->getParameter('transactionReference'),
        ];
    }

    public function sendData($data): RefundResponse
    {
        try {
            $stripe = new StripeClient($this->getApiKey());

            $refund = $stripe->refunds->create([
                'payment_intent' => $data['payment_intent'],
                'amount' => $data['amount'],
            ], $this->stripeOpts());

            return new RefundResponse($this, [
                'reference' => $refund->id,
                'error' => null,
            ]);
        } catch (ApiErrorException $e) {
            return new RefundResponse($this, [
                'reference' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
