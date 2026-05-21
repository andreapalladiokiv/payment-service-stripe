<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Captures a previously authorized Stripe PaymentIntent (pi_xxx).
 * Expects: transactionReference (pi_xxx). Optional: money (Money) for partial capture.
 */
final class CaptureRequest extends AbstractRequest
{
    use StripeRequestParameters;

    public function getData(): array
    {
        $this->validate('transactionReference');

        $data = [
            'payment_intent' => $this->getParameter('transactionReference'),
        ];

        /** @var Money|null $money */
        $money = $this->getParameter('money');
        if ($money !== null) {
            $data['amount'] = (int) $money->getAmount();
        }

        return $data;
    }

    public function sendData($data): CaptureResponse
    {
        try {
            $stripe = new StripeClient($this->getApiKey());

            $captureParams = [];
            if (isset($data['amount'])) {
                $captureParams['amount_to_capture'] = $data['amount'];
            }

            $paymentIntent = $stripe->paymentIntents->capture($data['payment_intent'], $captureParams, $this->stripeOpts());

            return new CaptureResponse($this, [
                'reference' => $paymentIntent->id,
                'error' => null,
            ]);
        } catch (ApiErrorException $e) {
            return new CaptureResponse($this, [
                'reference' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
