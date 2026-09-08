<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Stripe\PaymentIntent;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Stripe\PaymentIntentOutcome;

/**
 * What a Stripe PaymentIntent means for a payment.
 *
 * These assertions used to live on `StripeResponse`, a class that held an array and answered
 * `isSuccessful()` / `getChallenge()` / `getCvcCheck()` through interfaces so a shared assembler
 * could fold it into a result. Both are gone, and with them the thing those tests were really
 * pinning: whether the keys the request put in the array were the keys the response read back
 * out. There is no array and no second reader — the intent is mapped straight onto an
 * {@see \Techork\PaymentService\Gateway\Contract\AuthorizationResult} here — so the same
 * questions are asked of the mapping instead, with a real `PaymentIntent` as the input.
 *
 * Inputs are built with `PaymentIntent::constructFrom()`, which is how the SDK itself hydrates
 * them, so the nesting and the `__isset` behaviour on absent keys are the real ones.
 */
function stripeIntent(array $attributes): PaymentIntent
{
    return PaymentIntent::constructFrom(['object' => 'payment_intent'] + $attributes);
}

function stripeIntentWithChecks(array $checks): PaymentIntent
{
    return stripeIntent([
        'id' => 'pi_123',
        'status' => 'requires_capture',
        'payment_method' => [
            'id' => 'pm_1',
            'object' => 'payment_method',
            'card' => ['checks' => $checks],
        ],
    ]);
}

it('reads the AVS and CVC checks off the expanded payment method', function () {
    $result = new PaymentIntentOutcome()->map(stripeIntentWithChecks([
        'address_line1_check' => 'pass',
        'address_postal_code_check' => 'fail',
        'cvc_check' => 'unavailable',
    ]), null, 'requires_capture');

    expect($result->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Fail)
        ->and($result->cvcCheck)->toBe(CheckResult::Unavailable);
});

it('returns null for a check Stripe did not report', function () {
    $result = new PaymentIntentOutcome()->map(
        stripeIntent(['id' => 'pi_123', 'status' => 'requires_capture']),
        null,
        'requires_capture',
    );

    expect($result->addressLineCheck)->toBeNull()
        ->and($result->postalCodeCheck)->toBeNull()
        ->and($result->cvcCheck)->toBeNull()
        ->and($result->hasChecks())->toBeFalse();
});

/**
 * `unchecked` is a signal, not an absence: it says the issuer was asked and declined to answer,
 * which is a different fact from never having asked. Collapsing the two would let a merchant
 * decline on a check nobody ran.
 */
it('treats Unchecked as a real signal, distinct from absence', function () {
    $result = new PaymentIntentOutcome()->map(
        stripeIntentWithChecks(['address_line1_check' => 'unchecked']),
        null,
        'requires_capture',
    );

    expect($result->addressLineCheck)->toBe(CheckResult::Unchecked)
        ->and($result->postalCodeCheck)->toBeNull();
});

it('surfaces the FX-settled amount from the expanded balance transaction', function () {
    $result = new PaymentIntentOutcome()->map(stripeIntent([
        'id' => 'pi_123',
        'status' => 'succeeded',
        'currency' => 'jpy',
        'latest_charge' => [
            'id' => 'ch_1',
            'object' => 'charge',
            'currency' => 'jpy',
            'balance_transaction' => [
                'id' => 'txn_1',
                'object' => 'balance_transaction',
                'amount' => 5712,
                'currency' => 'usd',
            ],
        ],
    ]), null, 'succeeded');

    expect($result->convertedAmount)->toEqual(new Money(5712, new Currency('USD')));
});

it('reports no converted amount when nothing was converted', function () {
    $result = new PaymentIntentOutcome()->map(
        stripeIntent(['id' => 'pi_123', 'status' => 'succeeded', 'currency' => 'usd']),
        null,
        'succeeded',
    );

    expect($result->convertedAmount)->toBeNull();
});

/**
 * The status is what decides, not the presence of an id — a payment intent has one in every
 * state, `requires_action` included, so reading success off the id reported a card still owing
 * 3DS as an authorization and the caller captured money nobody was holding.
 */
it('reports success only at the status the operation named', function () {
    $intent = stripeIntent(['id' => 'pi_123', 'status' => 'requires_capture']);

    expect(new PaymentIntentOutcome()->map($intent, null, 'requires_capture')->success)->toBeTrue()
        ->and(new PaymentIntentOutcome()->map($intent, null, 'succeeded')->success)->toBeFalse();
});

it('names the status it got and the one it wanted when they differ', function () {
    $result = new PaymentIntentOutcome()->map(
        stripeIntent(['id' => 'pi_123', 'status' => 'requires_payment_method']),
        null,
        'requires_capture',
    );

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toContain('requires_payment_method')
        ->and($result->message)->toContain('requires_capture');
});

/**
 * A challenge is neither success nor failure: the payer has somewhere to go and the money is not
 * yet held. The reference still points at the intent so a later confirm or webhook can resolve
 * it, and no converted amount is claimed, because nothing has settled.
 */
it('parks at requires-action when there is a step-up to present', function () {
    $challenge = Mockery::mock(Challenge::class);

    $result = new PaymentIntentOutcome()->map(
        stripeIntent(['id' => 'pi_123', 'status' => 'requires_action']),
        $challenge,
        'requires_capture',
    );

    expect($result->isRequiresAction())->toBeTrue()
        ->and($result->challenge)->toBe($challenge)
        ->and($result->success)->toBeFalse()
        ->and($result->reference)->toBe('pi_123')
        ->and($result->message)->toBeNull()
        ->and($result->convertedAmount)->toBeNull();
});

/**
 * Which transaction OPENED the intent, recorded because `reference` cannot answer it later — the
 * stored row is overwritten on transition, so once a capture lands it holds the settle reference.
 * Only the opening operations map through here, which is what keeps a capture from burying the
 * anchor under its own reference.
 */
it('records the opening transaction reference on every outcome it can address', function (string $status, string $expected) {
    $result = new PaymentIntentOutcome()->map(stripeIntent(['id' => 'pi_opened', 'status' => $status]), null, $expected);

    expect($result->metadata)->toHaveKey('opening_transaction_reference', 'pi_opened');
})->with([
    'authorization' => ['requires_capture', 'requires_capture'],
    'charge' => ['succeeded', 'succeeded'],
]);

it('records no opening reference on an outcome that addresses nothing', function () {
    $result = new PaymentIntentOutcome()->map(
        stripeIntent(['id' => 'pi_123', 'status' => 'requires_payment_method']),
        null,
        'succeeded',
    );

    expect($result->metadata)->toBe([]);
});
