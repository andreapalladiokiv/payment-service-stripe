<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Stripe\PaymentIntent;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;

/**
 * Reads the step-up out of a Stripe payment intent that came back `requires_action`.
 *
 * Stripe answers in one of two shapes, and only one of them is a challenge this package can
 * describe:
 *
 *  - `redirect_to_url` — an address to send the cardholder to. That is a step-up: there is
 *    somewhere to go, and a client can act on it with no Stripe code involved.
 *  - `use_stripe_sdk` — everything happens inside Stripe.js, which holds the client secret and
 *    conducts the authentication itself. There is no address, because the browser is never
 *    given one.
 *
 * The second returns null rather than a challenge with the client secret in it, which is what
 * this used to do. A {@see ThreeDSChallenge} requires a url precisely so that a payment cannot be
 * held against a step nobody was told how to take — and a client secret is not a substitute, it
 * is a credential for a different integration style that this service does not drive. Reporting
 * no challenge lets the caller treat the payment as unresolved rather than parking a cardholder
 * in front of nothing.
 *
 * Switching to Stripe's SDK flow properly means the client, not this package, running the
 * authentication; until then, request `redirect_to_url` behaviour from Stripe or expect this to
 * decline to describe it.
 */
final readonly class StripeChallenge
{
    public static function from(PaymentIntent $paymentIntent): ?ThreeDSChallenge
    {
        if ($paymentIntent->status !== 'requires_action') {
            return null;
        }

        // Ask what the action is before reaching for the address. Stripe's SDK answers an
        // unknown property with a logged notice, so reading `redirect_to_url` off a
        // `use_stripe_sdk` action wrote a line of noise for every 3DS payment — on exactly
        // the path where the log is worth reading.
        $nextAction = $paymentIntent->next_action;
        if ($nextAction === null || $nextAction->type !== 'redirect_to_url') {
            return null;
        }

        $url = $nextAction->redirect_to_url?->url;

        if (! is_string($url) || $url === '') {
            return null;
        }

        // Stripe's own payment intent id. Not a `threeDSServerTransID`: Stripe conducts the
        // authentication itself and does not publish the protocol's identifier, so this is the
        // only handle either side shares — which is also why a result arriving from Stripe is
        // correlated by it and not by a directory server's transaction id.
        return new ThreeDSChallenge(
            authenticationId: (string) $paymentIntent->id,
            url: $url,
        );
    }
}
