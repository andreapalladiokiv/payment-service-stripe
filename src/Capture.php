<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Techork\PaymentService\Stripe\Concern\ExtractsConvertedAmount;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Money\Money;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;

/**
 * Captures a previously authorized Stripe PaymentIntent (pi_xxx).
 * Expects: transactionReference (pi_xxx). Optional: money (Money) for partial capture.
 */
final class Capture
{
    use ExtractsConvertedAmount;
    use StripeRequestParameters;

    public function __construct(
        private readonly StripeSettings $settings,
        private readonly CaptureCommand $command,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {

        $data = [
            'payment_intent' => $this->command->transactionReference,
        ];

        /** @var Money|null $money */
        $data['amount'] = (int) $this->command->amount->getAmount();

        return $data;
    }

    public function capture(): GatewayResult
    {
        $data = $this->payload();

        try {
            $stripe = new StripeClient($this->settings->apiKey);

            $paymentIntent = $stripe->paymentIntents->capture($data['payment_intent'], [
                'expand' => ['latest_charge.balance_transaction'],
                'amount_to_capture' => $data['amount'],
            ], $this->stripeOpts($this->command->clientUniqueId));

            return GatewayResult::succeeded($paymentIntent->id)
                ->withConvertedAmount($this->extractConvertedAmount($paymentIntent));
        } catch (ApiErrorException $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }
}
