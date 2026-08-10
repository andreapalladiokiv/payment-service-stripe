<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Stripe\PurchaseRequest;

/**
 * Verifies the {@see Techork\PaymentService\Stripe\Concern\StripeRequestParameters::stripeOpts}
 * helper that builds the Stripe SDK opts array carrying `idempotency_key`.
 *
 * The trait is exercised through a concrete request class (PurchaseRequest)
 * via reflection — the helper itself is private to all consumers of the
 * trait so we go through one of them.
 */
function makeStripeRequestForOpts(?string $clientUniqueId): PurchaseRequest
{
    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize($clientUniqueId === null ? [] : ['clientUniqueId' => $clientUniqueId]);

    return $request;
}

function callStripeOpts(PurchaseRequest $request, ?string $scope = null): array
{
    return new ReflectionMethod($request, 'stripeOpts')->invoke($request, $scope);
}

it('returns empty opts when clientUniqueId is null', function () {
    expect(callStripeOpts(makeStripeRequestForOpts(null)))->toBe([]);
});

it('returns empty opts when clientUniqueId is empty string', function () {
    expect(callStripeOpts(makeStripeRequestForOpts('')))->toBe([]);
});

it('emits idempotency_key opt when clientUniqueId is set', function () {
    expect(callStripeOpts(makeStripeRequestForOpts('pi-uuid-7')))
        ->toBe(['idempotency_key' => 'pi-uuid-7']);
});

it('derives a distinct key per endpoint scope', function () {
    $request = makeStripeRequestForOpts('pm-uuid-3');

    expect(callStripeOpts($request, 'payment_method'))->toBe(['idempotency_key' => 'pm-uuid-3:payment_method'])
        ->and(callStripeOpts($request, 'setup_intent'))->toBe(['idempotency_key' => 'pm-uuid-3:setup_intent'])
        ->and(callStripeOpts($request))->toBe(['idempotency_key' => 'pm-uuid-3']);
});

it('keeps a scoped key stable so a retry still deduplicates', function () {
    $first = makeStripeRequestForOpts('pm-uuid-3');
    $retry = makeStripeRequestForOpts('pm-uuid-3');

    expect(callStripeOpts($retry, 'setup_intent'))->toBe(callStripeOpts($first, 'setup_intent'));
});

it('stays empty under a scope when there is no id to derive from', function () {
    expect(callStripeOpts(makeStripeRequestForOpts(null), 'setup_intent'))->toBe([]);
});

/**
 * Stripe binds an idempotency key to the endpoint that first used it, so two calls
 * in one request class must not share one. The id is stable, which makes such a
 * collision permanent rather than transient: every retry burns on it and the
 * operation can never complete. The failure surfaces far from here — as an
 * instrument that is never registered — so it is worth catching structurally.
 */
it('never hands the same idempotency key to two Stripe endpoints', function () {
    $offenders = [];

    foreach (glob(__DIR__.'/../../../../src/*.php') ?: [] as $file) {
        preg_match_all('/\$this->stripeOpts\(([^)]*)\)/', (string) file_get_contents($file), $matches);

        $scopes = array_map(trim(...), $matches[1]);
        if (count($scopes) > 1 && count(array_unique($scopes)) !== count($scopes)) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

it('round-trips the clientUniqueId via getter/setter', function () {
    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);

    expect($request->getClientUniqueId())->toBeNull();

    $request->setClientUniqueId('refund-uuid-9');
    expect($request->getClientUniqueId())->toBe('refund-uuid-9');

    $request->setClientUniqueId(null);
    expect($request->getClientUniqueId())->toBeNull();
});
