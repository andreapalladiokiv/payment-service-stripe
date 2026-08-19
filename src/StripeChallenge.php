<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Stripe\PaymentIntent;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\Challenge\SdkChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;

/**
 * Reads the step-up out of a Stripe payment intent that came back `requires_action`.
 *
 * Stripe answers in one of two shapes, and only one of them arrives as an address:
 *
 *  - `redirect_to_url` — somewhere to send the cardholder, hosted by Stripe. Available
 *    only when a `return_url` was configured, because that address is where its page
 *    hands the browser back.
 *  - `use_stripe_sdk` — no address at all. It means "run our JavaScript": Stripe.js takes
 *    the intent's client secret and conducts the authentication in an iframe on a page of
 *    yours. Everything it needs is already in this response.
 *
 * Every other gateway in this package returns an address of its own — ConnexPay a
 * `redirectUrl`, Nuvei an `acsUrl` — so the second shape is the odd one out, and used to
 * be reported as no challenge at all. The caller then read the payment as authorized and
 * captured money that was never held.
 *
 * It is now reported as itself, {@see SdkChallenge}. Forcing it into an address was the
 * intermediate step: a url minted from a configured prefix, which obliged the caller to host
 * a page that only existed to receive it, and refused the payment outright when nothing was
 * configured. The client secret is not carried either way — see {@see SdkChallenge} for why
 * a challenge is the wrong place for a credential.
 *
 * A `use_stripe_sdk` action is describable with nothing configured at all — it is
 * {@see SdkChallenge}, which carries a handle rather than an address. The configured page and
 * the return url stay as ways to *choose* a hosted form instead, not as conditions without
 * which a payment is refused.
 *
 * Null is reserved for an action shape this package has never seen. The caller must not read
 * that as success; {@see AuthorizeResponse} decides success from the status for that reason.
 */
final readonly class StripeChallenge
{
    public static function from(PaymentIntent $paymentIntent, ?string $authenticationUrl = null): ?Challenge
    {
        if ($paymentIntent->status !== 'requires_action') {
            return null;
        }

        // Ask what the action is before reaching for its fields. Stripe's SDK answers an
        // unknown property with a logged notice, so reading `redirect_to_url` off a
        // `use_stripe_sdk` action wrote a line of noise for every 3DS payment.
        $nextAction = $paymentIntent->next_action;
        if ($nextAction === null) {
            return null;
        }

        return match ($nextAction->type) {
            'redirect_to_url' => self::hostedByStripe($paymentIntent, $nextAction),
            'use_stripe_sdk' => self::conductedBySdk($paymentIntent, $nextAction, $authenticationUrl),
            default => null,
        };
    }

    private static function hostedByStripe(PaymentIntent $paymentIntent, object $nextAction): ?ThreeDSChallenge
    {
        $url = $nextAction->redirect_to_url?->url;

        if (! is_string($url) || $url === '') {
            return null;
        }

        return new ThreeDSChallenge(
            authenticationId: (string) $paymentIntent->id,
            url: $url,
        );
    }

    private static function conductedBySdk(PaymentIntent $paymentIntent, object $nextAction, ?string $authenticationUrl): Challenge
    {
        // Stripe publishes the protocol's own identifier here — a docblock in this file used
        // to claim it does not. `server_transaction_id` is the `threeDSServerTransID`, the
        // value the directory server keeps too, so a later result matches on it. The intent
        // id stands in if a shape without it ever arrives.
        $serverTransactionId = $nextAction->use_stripe_sdk?->server_transaction_id;
        $authenticationId = is_string($serverTransactionId) && $serverTransactionId !== ''
            ? $serverTransactionId
            : (string) $paymentIntent->id;

        // A configured page is an opt-in, not a precondition. A caller that would rather
        // receive an address — because its client only knows how to open one — gets one
        // minted from the prefix; a caller that has not asked for that gets the shape Stripe
        // actually answered with, which needs no address at all.
        if ($authenticationUrl !== null) {
            return new ThreeDSChallenge(
                authenticationId: $authenticationId,
                url: $authenticationUrl.'/'.$paymentIntent->id,
            );
        }

        return new SdkChallenge(
            authenticationId: $authenticationId,
            paymentReference: (string) $paymentIntent->id,
        );
    }
}
