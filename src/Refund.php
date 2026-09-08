<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Money\Money;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;

/**
 * Refunds a Stripe PaymentIntent (pi_xxx).
 * Expects: money (Money), transactionReference (pi_xxx).
 */
final class Refund
{
    use StripeRequestParameters;

    public function __construct(
        private readonly StripeSettings $settings,
        private readonly RefundCommand $command,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {

        /** @var Money $money */
        $money = $this->command->amount;

        return [
            'amount' => (int) $money->getAmount(),
            'payment_intent' => $this->command->transactionReference,
        ];
    }

    public function refund(): GatewayResult
    {
        $data = $this->payload();

        try {
            $stripe = new StripeClient($this->settings->apiKey);

            $refund = $stripe->refunds->create([
                'payment_intent' => (string) $data['payment_intent'],
                'amount' => (int) $data['amount'],
            ], $this->stripeOpts($this->command->clientUniqueId));

            return GatewayResult::succeeded($refund->id);
        } catch (ApiErrorException $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }
}
