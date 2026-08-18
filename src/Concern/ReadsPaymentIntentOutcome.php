<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Concern;

use Stripe\PaymentIntent;
use Techork\PaymentService\Common\Contract\Challenge;

/**
 * Turns a Stripe payment intent's status into the answer the caller is owed.
 *
 * Success used to be read off the presence of an id, and a payment intent has an id in
 * every state Stripe will return — including `requires_action`. So a card that asked for
 * 3DS came back as an authorization, the caller booked money that was never held, and the
 * mistake surfaced at capture as an acquirer refusal, far from the cardholder who could
 * still have answered their issuer.
 *
 * The status is the fact, and each operation names the one status that means it did what
 * it promised: `requires_capture` for an authorization, `succeeded` for a charge.
 * Everything else is refused, so a status this package has never heard of arrives as a
 * refusal rather than as a success.
 *
 * A refusal has to say what happened, which is the second half of the same bug: when the
 * step-up cannot be described {@see \Techork\PaymentService\Stripe\StripeChallenge}
 * answers null, and null is also what "no step-up at all" looks like. The distinction is
 * restored here, in words, on the response's message.
 */
trait ReadsPaymentIntentOutcome
{
    /**
     * Why this payment intent is not the outcome the operation promised, or null when it is
     * — or when it is a step-up the caller has been handed and can present.
     */
    protected function explainUnusableOutcome(PaymentIntent $paymentIntent, ?Challenge $challenge, string $expected): ?string
    {
        $status = (string) $paymentIntent->status;

        if ($status === $expected || $challenge !== null) {
            return null;
        }

        if ($status === 'requires_action') {
            $type = $paymentIntent->next_action?->type;

            return sprintf(
                'Stripe left the payment intent at `requires_action` with next_action `%s`, and this gateway is configured '
                .'to present neither shape. Give the credential an `authenticationUrl` for the page that runs Stripe.js, '
                .'or a `returnUrl` so Stripe hosts the step-up itself.',
                is_string($type) && $type !== '' ? $type : 'unset',
            );
        }

        return sprintf('Stripe left the payment intent at `%s`; `%s` was expected.', $status, $expected);
    }
}
