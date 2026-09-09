<?php

declare(strict_types=1);

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\Stripe\StripeSettings;
use Techork\PaymentService\Stripe\UpdateCustomer;

/**
 * {@see UpdateCustomer} was entirely unexecuted, and exercising it turned up
 * two things that only unexecuted code can hide.
 *
 * Every input it read — `customerReference`, `email` and the five address keys —
 * came out of omnipay's parameter bag, and none of them had a `set…()` on the
 * class, on {@see \Techork\PaymentService\Stripe\Concern\StripeRequestParameters}
 * or on omnipay's `AbstractRequest`. `Helper::initialize()` applies an option
 * only when a matching setter exists and discards the rest without a word, so
 * via the normal path not one of them could arrive.
 *
 * The consequences were: an always-empty payload, and a call reaching Stripe
 * with a null customer id, where the SDK throws
 * `Stripe\Exception\InvalidArgumentException` — which is NOT an
 * `ApiErrorException` and therefore escaped this class's own catch instead of
 * becoming a failed result. All seven values are constructor arguments now, and
 * the missing reference is refused before the SDK sees it.
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

function stripeUpdateCustomer(array $options = []): UpdateCustomer
{
    return new UpdateCustomer(
        new StripeSettings($options['apiKey'] ?? 'sk_test_fake'),
        $options['customerReference'] ?? '',
        $options['email'] ?? '',
        // One customer where an address used to arrive alone. The `billingAddress` key stays
        // because what each test says has not changed; where it lives has.
        array_key_exists('customer', $options)
            ? $options['customer']
            : (isset($options['billingAddress']) ? stripeSuiteCustomer(address: $options['billingAddress']) : null),
    );
}

/**
 * The address the operation now reads, assembled from the same loose keys these tests used to
 * write straight into the parameter bag. See {@see stripeCreateCustomerAddress()} for why the bag
 * was the only way to reach the code at all before the constructor took them.
 */
function stripeUpdateCustomerAddress(array $keys): ?BillingAddress
{
    if (array_intersect_key($keys, array_flip(['address', 'city', 'country', 'postal_code', 'state'])) === []) {
        return null;
    }

    $country = new Country($keys['country'] ?? 'US');

    return new BillingAddress(
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
//  payload()
// ──────────────────────────────────────────────

/**
 * Worth pinning as the empty array rather than as `['address' => []]`: Stripe
 * reads an explicitly sent empty `address` as an instruction to clear the
 * stored one, so the outer `array_filter` dropping the empty nested array is
 * the only thing keeping a no-op from being destructive.
 */
it('produces an empty payload when there is nothing to update', function () {
    // Was asserting [] for an email plus a city, because neither could reach the operation at
    // all. Both arrive now, so the empty payload is what a genuinely empty update produces —
    // which still matters, for the reason above.
    expect(stripeUpdateCustomer(['customerReference' => 'cus_1'])->payload())->toBe([]);
});

it('carries an email-only update now that the email can reach it', function () {
    expect(stripeUpdateCustomer(['customerReference' => 'cus_1', 'email' => 'new@example.com'])->payload())
        ->toBe(['email' => 'new@example.com']);
});

/**
 * The shape the class means to build. Stripe's key names are `line1` and
 * `postal_code`, and a mismatched key is dropped by Stripe rather than rejected
 * — so a wrong name here would leave the customer partly updated with nothing
 * reporting it.
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

    $data = stripeUpdateCustomer([
        'customerReference' => 'cus_1',
        'email' => $keys['email'],
        'billingAddress' => stripeUpdateCustomerAddress($keys),
    ])->payload();

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
    expect(stripeUpdateCustomer([
        'customerReference' => 'cus_1',
        'billingAddress' => stripeUpdateCustomerAddress(['city' => 'Berlin', 'country' => 'DE']),
    ])->payload()['address'])->toBe(['city' => 'Berlin', 'country' => 'DE']);
});

/**
 * An email-only update carries no address key at all, so the customer's stored
 * address survives it.
 */
it('leaves the stored address untouched on an email-only update', function () {
    expect(stripeUpdateCustomer(['customerReference' => 'cus_1', 'email' => 'new@example.com'])->payload())
        ->toBe(['email' => 'new@example.com']);
});

// ──────────────────────────────────────────────
//  update()
// ──────────────────────────────────────────────

/**
 * The result echoes the customer id the caller already had, not anything read
 * back from Stripe — the stub answers with `cus_remote` and the reference is
 * still `cus_local`. That is intentional for an update (the id cannot change),
 * and pinned because it means the result proves nothing about what Stripe
 * stored.
 */
it('echoes the caller customer id as the reference rather than the API response', function () {
    $api = stripeUpdateCustomerFakeApi(['id' => 'cus_remote', 'object' => 'customer']);

    $result = stripeUpdateCustomer([
        'customerReference' => 'cus_local',
        'email' => 'new@example.com',
    ])->update();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('cus_local')
        ->and($result->message)->toBeNull()
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

    stripeUpdateCustomer([
        'customerReference' => 'cus_local',
        'email' => 'new@example.com',
    ])->update();

    expect(implode("\n", $api->calls[0]['headers']))->not->toContain('Idempotency-Key');
});

it('converts a Stripe API error into a failed result carrying the reason', function () {
    stripeUpdateCustomerFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'No such customer: cus_gone']],
        404,
    );

    $result = stripeUpdateCustomer([
        'customerReference' => 'cus_gone',
        'email' => 'new@example.com',
    ])->update();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toBe('No such customer: cus_gone');
});

/**
 * The defect, made executable.
 *
 * `customerReference` had no setter, so `initialize()` dropped it and the
 * operation called `customers->update(null, …)`. Stripe's SDK rejects that with
 * `Stripe\Exception\InvalidArgumentException`, which does not extend
 * `ApiErrorException` — so the catch missed it and the exception left the
 * operation entirely instead of becoming the failed result every other path
 * here produces. That is the difference between a recorded failure and an
 * unhandled error.
 *
 * The stub is installed even though no HTTP call is reached, so that a change
 * which does start sending cannot silently reach the real API from this test.
 */
it('reports a missing customer id as a failed result instead of escaping its own catch', function () {
    stripeUpdateCustomerFakeApi(['id' => 'cus_x', 'object' => 'customer']);

    $result = stripeUpdateCustomer()->update();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('No Stripe customer reference');
});

it('now delivers the customer id it is given', function () {
    expect(stripeUpdateCustomer(['customerReference' => 'cus_kept'])->customerReference)->toBe('cus_kept');
});
