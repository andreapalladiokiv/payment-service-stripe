<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Stripe\PaymentIntent;
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
 * So an address is minted for it, pointing at the page this deployment hosts for exactly
 * this ({@see StripeGateway::setAuthenticationUrl}). The challenge then looks the same
 * whichever shape Stripe chose, and nothing downstream has to know Stripe has two.
 *
 * The client secret is deliberately NOT carried here. The page is addressed by the
 * payment intent's own id and fetches the secret server-side with the merchant's key, so
 * it reaches the one browser about to authenticate and never the event stream — and
 * {@see ThreeDSChallenge} keeps a url as its point, rather than becoming a carrier for a
 * credential that could confirm the payment outright.
 *
 * With neither an authentication page nor a return url configured there is nothing to
 * put in front of anyone, and null is the honest answer. The caller must not read that as
 * success; {@see AuthorizeResponse} decides success from the status for that reason.
 */
final readonly class StripeChallenge
{
    public static function from(PaymentIntent $paymentIntent, ?string $authenticationUrl = null): ?ThreeDSChallenge
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
            'use_stripe_sdk' => self::hostedByUs($paymentIntent, $nextAction, $authenticationUrl),
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

    private static function hostedByUs(PaymentIntent $paymentIntent, object $nextAction, ?string $authenticationUrl): ?ThreeDSChallenge
    {
        if ($authenticationUrl === null) {
            return null;
        }

        // Stripe does publish the protocol's own identifier here — this docblock used to
        // claim it does not. `server_transaction_id` is the `threeDSServerTransID`, which
        // is a better handle than the payment intent's id because it is the one the
        // directory server and any 3DS record keep too. The intent id stands in when a
        // shape without it arrives, since the page is addressed by that id regardless.
        $serverTransactionId = $nextAction->use_stripe_sdk?->server_transaction_id;

        return new ThreeDSChallenge(
            authenticationId: is_string($serverTransactionId) && $serverTransactionId !== ''
                ? $serverTransactionId
                : (string) $paymentIntent->id,
            url: $authenticationUrl.'/'.$paymentIntent->id,
        );
    }
}
