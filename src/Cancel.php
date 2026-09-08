<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;

/**
 * Cancels (voids) a Stripe PaymentIntent (pi_xxx).
 * Expects: transactionReference (pi_xxx).
 */
final class Cancel
{
    use StripeRequestParameters;

    public function __construct(
        private readonly StripeSettings $settings,
        private readonly CancelCommand $command,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {

        return [
            'payment_intent' => $this->command->transactionReference,
        ];
    }

    public function cancel(): GatewayResult
    {
        $data = $this->payload();

        try {
            $stripe = new StripeClient($this->settings->apiKey);

            $paymentIntent = $stripe->paymentIntents->cancel($data['payment_intent'], [], $this->stripeOpts($this->command->clientUniqueId));

            // Stripe answers 200 for a cancel it did not perform, so the status is the answer
            // rather than the absence of an exception.
            //
            // The wording of the refusal is the one the shared folder produced when a response
            // reported itself unsuccessful and carried no message of its own — which is what a
            // Stripe cancel that left the intent alone always did. Naming the status here would
            // read better and is deliberately not done: it would change what a merchant is told.
            return $paymentIntent->status === 'canceled'
                ? GatewayResult::succeeded($paymentIntent->id)
                : GatewayResult::failed('Gateway returned an unsuccessful response.');
        } catch (ApiErrorException $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }
}
