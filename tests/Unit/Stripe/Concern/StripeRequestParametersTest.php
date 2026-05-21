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

function callStripeOpts(PurchaseRequest $request): array
{
    return new ReflectionMethod($request, 'stripeOpts')->invoke($request);
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

it('round-trips the clientUniqueId via getter/setter', function () {
    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);

    expect($request->getClientUniqueId())->toBeNull();

    $request->setClientUniqueId('refund-uuid-9');
    expect($request->getClientUniqueId())->toBe('refund-uuid-9');

    $request->setClientUniqueId(null);
    expect($request->getClientUniqueId())->toBeNull();
});
