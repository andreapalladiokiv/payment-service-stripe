<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Omnipay\Common\Message\AbstractRequest;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;

/**
 * Cancels (voids) a Stripe PaymentIntent (pi_xxx).
 * Expects: transactionReference (pi_xxx).
 */
final class VoidRequest extends AbstractRequest
{
    use StripeRequestParameters;

    public function getData(): array
    {
        $this->validate('transactionReference');

        return [
            'payment_intent' => $this->getParameter('transactionReference'),
        ];
    }

    public function sendData($data): VoidResponse
    {
        try {
            $stripe = new StripeClient($this->getApiKey());

            $paymentIntent = $stripe->paymentIntents->cancel($data['payment_intent'], [], $this->stripeOpts());

            return new VoidResponse($this, [
                'reference' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'error' => null,
            ]);
        } catch (ApiErrorException $e) {
            return new VoidResponse($this, [
                'reference' => null,
                'status' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
