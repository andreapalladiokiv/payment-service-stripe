<?php

declare(strict_types=1);

use Stripe\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Stripe\Concern\ExtractsCardChecks;

/**
 * {@see ExtractsCardChecks} turns Stripe's `card.checks` block into the three
 * response keys the gateway transport reads, and it was unexecuted. A wrong
 * answer here is silent and expensive in both directions: a mismatch reported as
 * a pass loses the AVS signal a merchant declines on, and "we did not check"
 * reported as a fail declines good customers.
 *
 * The trait's method is private to every consumer, so it is exercised through a
 * host class that exposes it — the same approach StripeRequestParametersTest
 * takes, but with a purpose-built host rather than a request class, since nothing
 * about the request participates in the mapping.
 *
 * Inputs are built with `PaymentMethod::constructFrom()`, which is how
 * {@see \Techork\PaymentService\Stripe\Webhook\EventParser} and the SDK itself
 * hydrate these objects — so the nesting and the `__isset` behaviour on absent
 * keys are the real ones, not an approximation.
 *
 * Helpers prefixed `stripeCardChecks…`.
 */
function stripeCardChecksHost(): object
{
    return new class
    {
        use ExtractsCardChecks;

        /** @return array{address_line_check: ?string, postal_code_check: ?string, cvc_check: ?string} */
        public function extract(?PaymentMethod $paymentMethod): array
        {
            return $this->extractStripeChecks($paymentMethod);
        }
    };
}

/** A PaymentMethod with exactly the given checks block, as Stripe expands one. */
function stripeCardChecksPaymentMethod(array $checks): PaymentMethod
{
    return PaymentMethod::constructFrom(['id' => 'pm_checks_1', 'card' => ['checks' => $checks]]);
}

it('maps every value in the enum vocabulary straight through', function (string $raw) {
    // Stripe pre-normalises its check strings to the same four words our
    // CheckResult enum uses, which is why the mapping is a tryFrom and not a
    // table. Each is pinned separately because the four are not interchangeable:
    // `unavailable` means the issuer could not verify, `unchecked` means nobody
    // asked, and a rule that treats one as the other either declines the innocent
    // or waves through the unverified.
    $result = stripeCardChecksHost()->extract(stripeCardChecksPaymentMethod([
        'address_line1_check' => $raw,
        'address_postal_code_check' => $raw,
        'cvc_check' => $raw,
    ]));

    expect($result)->toBe([
        'address_line_check' => $raw,
        'postal_code_check' => $raw,
        'cvc_check' => $raw,
    ])
        // The round trip through the enum is the actual claim: the string survives
        // only because a case carries it.
        ->and(CheckResult::tryFrom($raw)?->value)->toBe($raw);
})->with([
    'pass' => CheckResult::Pass->value,
    'fail' => CheckResult::Fail->value,
    'unavailable' => CheckResult::Unavailable->value,
    'unchecked' => CheckResult::Unchecked->value,
]);

it('reads each of the three checks from its own Stripe field', function () {
    // The three are named differently on each side — Stripe's
    // `address_line1_check` becomes our `address_line_check`, and
    // `address_postal_code_check` becomes `postal_code_check`. Distinct values
    // per field is what catches a copy-paste that reads one Stripe field into two
    // of our keys, which no single-value fixture would notice.
    expect(stripeCardChecksHost()->extract(stripeCardChecksPaymentMethod([
        'address_line1_check' => 'pass',
        'address_postal_code_check' => 'fail',
        'cvc_check' => 'unavailable',
    ])))->toBe([
        'address_line_check' => 'pass',
        'postal_code_check' => 'fail',
        'cvc_check' => 'unavailable',
    ]);
});

it('answers no signal for a value outside the enum vocabulary', function (string $raw) {
    // `tryFrom` yields null rather than throwing, and null is the honest answer:
    // an unrecognised word is not evidence of anything. Casing matters because
    // enum matching is exact, and a Stripe value we have never seen must not be
    // guessed at — the alternative, passing it through untranslated, would put a
    // string our own CheckResult cannot parse into the response data.
    $result = stripeCardChecksHost()->extract(stripeCardChecksPaymentMethod([
        'address_line1_check' => $raw,
        'address_postal_code_check' => $raw,
        'cvc_check' => $raw,
    ]));

    expect($result)->toBe([
        'address_line_check' => null,
        'postal_code_check' => null,
        'cvc_check' => null,
    ]);
})->with([
    'wrong case' => 'PASS',
    'a value Stripe does not document' => 'not_provided',
    'the empty string' => '',
    'an AVS response code, not a Stripe word' => 'Y',
]);

it('answers no signal for each check the block simply omits', function () {
    // Stripe drops keys rather than sending nulls. All three keys must still be
    // present in the result — the transport layer spreads this array into the
    // response data and a missing key would read as "not applicable to this
    // gateway" rather than "no signal".
    expect(stripeCardChecksHost()->extract(stripeCardChecksPaymentMethod(['cvc_check' => 'pass'])))
        ->toBe([
            'address_line_check' => null,
            'postal_code_check' => null,
            'cvc_check' => 'pass',
        ]);
});

it('answers no signal when the payment method was never expanded', function (?PaymentMethod $paymentMethod) {
    // The three shapes a non-expanded response takes. Each call site must ask
    // Stripe for `'expand' => ['payment_method']`; when it does not, the intent
    // carries a bare id string, the caller passes null, and this is what has to
    // come back. Silently returning nulls rather than throwing is deliberate: an
    // unexpanded response is a missing option, not a failed payment.
    expect(stripeCardChecksHost()->extract($paymentMethod))->toBe([
        'address_line_check' => null,
        'postal_code_check' => null,
        'cvc_check' => null,
    ]);
})->with([
    'no payment method at all' => null,
    'a payment method with no card' => fn () => PaymentMethod::constructFrom(['id' => 'pm_1']),
    'a card with no checks block' => fn () => PaymentMethod::constructFrom(['id' => 'pm_1', 'card' => ['brand' => 'visa']]),
]);

it('always answers with exactly the three documented keys', function () {
    // The shape is the contract with the transport layer, which spreads it into
    // response data. Pinned as a key list so an added or renamed key is a failing
    // test rather than an extra field appearing in stored gateway responses.
    expect(array_keys(stripeCardChecksHost()->extract(null)))
        ->toBe(['address_line_check', 'postal_code_check', 'cvc_check']);
});
