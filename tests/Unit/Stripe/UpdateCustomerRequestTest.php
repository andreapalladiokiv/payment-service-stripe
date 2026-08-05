<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidArgumentException as StripeInvalidArgumentException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Stripe\CreateCustomerResponse;
use Techork\PaymentService\Stripe\UpdateCustomerRequest;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\State;

/**
 * {@see UpdateCustomerRequest} was entirely unexecuted, and exercising it turns
 * up two things that only unexecuted code can hide.
 *
 * Every input this class reads — `customerReference`, `email` and the five
 * address keys — is read with `getParameter()`, and none of them has a
 * `set…()` on the class, on {@see \Techork\PaymentService\Stripe\Concern\StripeRequestParameters}
 * or on omnipay's `AbstractRequest`. Omnipay's `Helper::initialize()` applies an
 * option only when a matching setter exists and discards the rest without a
 * word, so via the normal path (`StripeGateway::updateCustomer($options)` →
 * `createRequest()` → `initialize()`) not one of them can arrive.
 *
 * The consequences are pinned below: `getData()` is always empty, and
 * `sendData()` reaches Stripe with a null customer id, where the SDK throws
 * `Stripe\Exception\InvalidArgumentException` — which is NOT an
 * `ApiErrorException` and therefore escapes this class's own catch instead of
 * becoming a failed response.
 *
 * These tests describe the defect deliberately. `updateCustomer()` has no
 * production caller today, which is why it has gone unnoticed; the first one
 * added will hit it. When setters land, invert these rather than delete them.
 *
 * Nothing here reaches the network. Stripe's SDK resolves its HTTP client
 * through the static `ApiRequestor::setHttpClient()`, and the `afterEach`
 * restores the real curl client.
 */
function stripeUpdateCustomerFakeApi(array $body, int $status = 200): object
{
    $client = new class($body, $status) implements ClientInterface
    {
        /** @var list<array{method: string, url: string, headers: array, params: array}> */
        public array $calls = [];

        public function __construct(private array $body, private int $status) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->calls[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

            return [json_encode($this->body), $this->status, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

function stripeUpdateCustomerRequest(array $options = []): UpdateCustomerRequest
{
    $request = new UpdateCustomerRequest(new OmnipayClient, new HttpRequest);
    $request->initialize($options + ['apiKey' => 'sk_test_fake']);

    return $request;
}

/**
 * Writes straight into the parameter bag, bypassing `initialize()`. The only
 * way to reach anything `getData()` and `sendData()` read, for the reason given
 * in the file docblock.
 */
function stripeUpdateCustomerWithBag(array $parameters): UpdateCustomerRequest
{
    $request = stripeUpdateCustomerRequest();
    $setParameter = new ReflectionMethod($request, 'setParameter');

    foreach ($parameters as $key => $value) {
        $setParameter->invoke($request, $key, $value);
    }

    return $request;
}

/**
 * The address the request now reads, assembled from the same loose keys these tests used to
 * write straight into the parameter bag.
 *
 * The bag was not a shortcut: `address`, `city`, `country`, `postal_code` and `state` had no
 * setters, so omnipay dropped every one and the bag was the only way to reach the code at all.
 * That was the defect. With `setBillingAddress` in place the ordinary `initialize()` path
 * reaches it, so these go through that instead — and an absent key stays absent rather than
 * arriving as an empty string, which is what `array_filter` in the request removes.
 */
function stripeUpdateCustomerAddress(array $keys): ?BillingAddress
{
    if (array_intersect_key($keys, array_flip(['address', 'city', 'country', 'postal_code', 'state'])) === []) {
        return null;
    }

    $country = new Country($keys['country'] ?? 'US');

    return new BillingAddress(
        firstName: 'Test',
        lastName: 'User',
        line: $keys['address'] ?? '',
        city: $keys['city'] ?? '',
        country: $country,
        postalCode: $keys['postal_code'] ?? '',
        state: isset($keys['state']) ? new State($keys['state'], $country) : null,
    );
}

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

// ──────────────────────────────────────────────
//  getData()
// ──────────────────────────────────────────────

/**
 * The payload is empty, so the request as reachable today is a guaranteed
 * no-op update. Worth pinning as the empty array rather than as
 * `['address' => []]`: Stripe reads an explicitly sent empty `address` as an
 * instruction to clear the stored one, so the outer `array_filter` dropping the
 * empty nested array is the only thing keeping a no-op from being destructive.
 */
it('produces an empty payload when there is nothing to update', function () {
    // Was asserting [] for an email plus a city, because neither could reach the request at
    // all. Both arrive now, so the empty payload is what a genuinely empty update produces —
    // which still matters: Stripe reads `address: {}` as an instruction to clear the stored
    // address, so an update with nothing to say must send nothing rather than a blank block.
    expect(stripeUpdateCustomerRequest(['customerReference' => 'cus_1'])->getData())->toBe([]);
});

it('carries an email-only update now that the email can reach it', function () {
    expect(stripeUpdateCustomerRequest(['customerReference' => 'cus_1', 'email' => 'new@example.com'])->getData())
        ->toBe(['email' => 'new@example.com']);
});

/**
 * The shape the class means to build, reached through the parameter bag. Stripe's
 * key names are `line1` and `postal_code`, and a mismatched key is dropped by
 * Stripe rather than rejected — so a wrong name here would leave the customer
 * partly updated with nothing reporting it.
 */
it('maps email and address onto the Stripe key names', function () {
    $keys = [
        'email' => 'new@example.com',
        'address' => '2 Ocean Drive',
        'city' => 'Miami',
        'country' => 'US',
        'postal_code' => '33139',
        'state' => 'FL',
    ];

    $data = stripeUpdateCustomerRequest([
        'customerReference' => 'cus_1',
        'email' => $keys['email'],
        'billingAddress' => stripeUpdateCustomerAddress($keys),
    ])->getData();

    expect($data)->toBe([
        'email' => 'new@example.com',
        'address' => [
            'line1' => '2 Ocean Drive',
            'city' => 'Miami',
            'country' => 'US',
            'postal_code' => '33139',
            'state' => 'FL',
        ],
    ]);
});

/**
 * A partial address sends only the parts that are known. Stripe merges the
 * address object wholesale, so sending nulls for the unknown fields would erase
 * them from the record — the filter is what makes a partial update partial.
 */
it('sends only the known address parts', function () {
    expect(stripeUpdateCustomerRequest([
        'customerReference' => 'cus_1',
        'billingAddress' => stripeUpdateCustomerAddress(['city' => 'Berlin', 'country' => 'DE']),
    ])->getData()['address'])->toBe(['city' => 'Berlin', 'country' => 'DE']);
});

/**
 * An email-only update carries no address key at all, so the customer's stored
 * address survives it.
 */
it('leaves the stored address untouched on an email-only update', function () {
    expect(stripeUpdateCustomerRequest(['customerReference' => 'cus_1', 'email' => 'new@example.com'])->getData())
        ->toBe(['email' => 'new@example.com']);
});

// ──────────────────────────────────────────────
//  sendData()
// ──────────────────────────────────────────────

/**
 * The response echoes the customer id the caller already had, not anything read
 * back from Stripe — the stub answers with `cus_remote` and the reference is
 * still `cus_local`. That is intentional for an update (the id cannot change),
 * and pinned because it means the response proves nothing about what Stripe
 * stored.
 */
it('echoes the caller customer id as the reference rather than the API response', function () {
    $api = stripeUpdateCustomerFakeApi(['id' => 'cus_remote', 'object' => 'customer']);

    $response = stripeUpdateCustomerWithBag([
        'customerReference' => 'cus_local',
        'email' => 'new@example.com',
    ])->send();

    expect($response)->toBeInstanceOf(CreateCustomerResponse::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('cus_local')
        ->and($response->getMessage())->toBeNull()
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/customers/cus_local')
        ->and($api->calls[0]['params'])->toBe(['email' => 'new@example.com']);
});

/**
 * No `Idempotency-Key`: the source passes no opts, which the package README
 * states outright. Safe here in a way it is not for capture or refund — an
 * update is idempotent by nature, since a repeat writes the same fields again.
 */
it('sends no idempotency key, which a repeatable update does not need', function () {
    $api = stripeUpdateCustomerFakeApi(['id' => 'cus_local', 'object' => 'customer']);

    stripeUpdateCustomerWithBag([
        'customerReference' => 'cus_local',
        'email' => 'new@example.com',
        'clientUniqueId' => 'update-uuid-4',
    ])->send();

    expect(implode("\n", $api->calls[0]['headers']))->not->toContain('Idempotency-Key');
});

it('converts a Stripe API error into a failed response carrying the reason', function () {
    stripeUpdateCustomerFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'No such customer: cus_gone']],
        404,
    );

    $response = stripeUpdateCustomerWithBag([
        'customerReference' => 'cus_gone',
        'email' => 'new@example.com',
    ])->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getTransactionReference())->toBeNull()
        ->and($response->getMessage())->toBe('No such customer: cus_gone');
});

/**
 * The defect, made executable.
 *
 * `customerReference` has no setter, so `initialize()` drops it and
 * `sendData()` calls `customers->update(null, …)`. Stripe's SDK rejects that
 * with `Stripe\Exception\InvalidArgumentException`, which does not extend
 * `ApiErrorException` — so the catch in `sendData()` misses it and the
 * exception leaves the request entirely instead of becoming the failed response
 * every other path here produces. In `PaymentGatewayRouter` terms that is the
 * difference between a recorded failure and an unhandled error.
 *
 * The stub is installed even though no HTTP call is reached, so that a fix
 * which does start sending cannot silently reach the real API from this test.
 */
it('reports a missing customer id as a failed response instead of escaping its own catch', function () {
    // `customerReference` had no setter, so it never arrived and `customers->update(null, …)`
    // threw Stripe's InvalidArgumentException — which is NOT an ApiErrorException, so it slipped
    // past the catch and left the request as an unhandled error where the router expects a
    // recorded failure. The reference reaches the request now, and its absence is refused here.
    stripeUpdateCustomerFakeApi(['id' => 'cus_x', 'object' => 'customer']);

    $response = stripeUpdateCustomerRequest()->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toContain('No Stripe customer reference');
});

it('now delivers the customer id it is given', function () {
    expect(stripeUpdateCustomerRequest(['customerReference' => 'cus_kept'])->getCustomerReference())
        ->toBe('cus_kept');
});
