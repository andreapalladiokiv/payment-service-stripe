<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Stripe\CreateCustomerRequest;
use Techork\PaymentService\Stripe\CreateCustomerResponse;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\State;

/**
 * {@see CreateCustomerRequest} was entirely unexecuted.
 *
 * It is the request that decides what a Stripe Customer record contains, and
 * `getData()` is built out of nested `array_filter` — a construct where the
 * difference between "sent as null", "sent as empty" and "not sent at all" is
 * invisible in the source and decisive at the API. Stripe treats an explicitly
 * sent empty `address` as an instruction to clear the one it holds, so which
 * keys survive the filter is the behaviour worth pinning.
 *
 * `sendData()` runs offline: Stripe's SDK resolves its HTTP client through the
 * static `ApiRequestor::setHttpClient()`, so a stub there answers the
 * `StripeClient` built inside it, and the `afterEach` restores the real curl
 * client.
 */
function stripeCreateCustomerFakeApi(array $body, int $status = 200): object
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

function stripeCreateCustomerRequest(array $parameters = []): CreateCustomerRequest
{
    $request = new CreateCustomerRequest(new OmnipayClient, new HttpRequest);
    $request->initialize($parameters + ['apiKey' => 'sk_test_fake']);

    return $request;
}

/**
 * Writes straight into the parameter bag, bypassing `initialize()`.
 *
 * Needed because `initialize()` applies an option only when the request
 * declares a matching `set…()`, and this class declares one for `email`
 * alone — so the address keys `getData()` reads can be reached no other way.
 * The gap itself is pinned in its own test below; this helper exists so the
 * address-assembly logic can still be exercised.
 */
function stripeCreateCustomerWithBag(array $parameters): CreateCustomerRequest
{
    $request = stripeCreateCustomerRequest();
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
function stripeCreateCustomerAddress(array $keys): ?BillingAddress
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
 * The address block must vanish completely, not arrive as an empty array.
 * `customers.create` with `address: {}` is a valid call that blanks the
 * address on the record, so an empty nested array is not a harmless no-op —
 * it is a destructive update. The `?: null` in the source is what collapses it,
 * and this is the assertion that says so.
 */
it('omits the address block entirely when no address part is known', function () {
    expect(stripeCreateCustomerRequest(['email' => 'buyer@example.com'])->getData())
        ->toBe(['email' => 'buyer@example.com']);
});

/**
 * Stripe's address keys are not ours: `line1`, `postal_code`. Pinned because a
 * mismatched key is accepted and dropped by Stripe rather than rejected, so the
 * customer is created with a partial address and nothing reports it.
 */
it('maps a known address onto the Stripe address key names', function () {
    $keys = [
        'email' => 'buyer@example.com',
        'address' => '1 Market Street',
        'city' => 'Miami',
        'country' => 'US',
        'postal_code' => '33101',
        'state' => 'FL',
    ];

    $data = stripeCreateCustomerRequest([
        'email' => $keys['email'],
        'billingAddress' => stripeCreateCustomerAddress($keys),
    ])->getData();

    expect($data)->toBe([
        'email' => 'buyer@example.com',
        'address' => [
            'line1' => '1 Market Street',
            'city' => 'Miami',
            'country' => 'US',
            'postal_code' => '33101',
            'state' => 'FL',
        ],
    ]);
});

/**
 * A partial address keeps the parts that are known and drops the rest, rather
 * than sending nulls. A null `state` on a Stripe address is written as a null,
 * which is a different record from one where the field was never mentioned.
 */
it('keeps the known address parts and drops the unknown ones', function () {
    $keys = ['city' => 'Berlin', 'country' => 'DE'];

    $data = stripeCreateCustomerRequest([
        'email' => 'buyer@example.com',
        'billingAddress' => stripeCreateCustomerAddress($keys),
    ])->getData();

    expect($data['address'])->toBe(['city' => 'Berlin', 'country' => 'DE']);
});

/**
 * `getEmail()` coalesces a missing email to '', and `array_filter` then removes
 * it — so a request with no email produces an empty payload and Stripe creates
 * an anonymous customer that no future lookup can find by email.
 *
 * Pinned as a guard on the callers rather than on this class:
 * {@see \Techork\PaymentService\Stripe\StripeGateway} and
 * {@see \Techork\PaymentService\Nuvei\NuveiGateway} both check for a billing
 * email before they get here, and this test is what makes it visible that the
 * request itself does not.
 */
it('produces an empty payload when no email was supplied', function () {
    expect(stripeCreateCustomerRequest()->getData())->toBe([]);
});

it('round-trips the email through its own accessor', function () {
    $request = new CreateCustomerRequest(new OmnipayClient, new HttpRequest);

    expect($request->getEmail())->toBe('');

    $request->setEmail('buyer@example.com');
    expect($request->getEmail())->toBe('buyer@example.com');
});

/**
 * The address keys `getData()` reads have no `set…()` on this class, and
 * omnipay's `Helper::initialize()` discards any option without one — so an
 * address passed as a request option never arrives, and every Stripe Customer
 * this request creates carries an email and nothing else.
 *
 * This test documents a defect, not an intention. `NuveiGateway` builds its
 * `createCustomer()` options with address, city, postal_code and state, all of
 * which are silently lost. The fix is setters on the request (or building the
 * address from the `BillingAddress` value object the way
 * {@see \Techork\PaymentService\Stripe\Concern\StripeRequestParameters::formatBillingDetails}
 * does); when that lands, this test should be inverted rather than deleted.
 */
it('cannot receive an address as a request option because no setter accepts one', function () {
    $request = stripeCreateCustomerRequest([
        'email' => 'buyer@example.com',
        'address' => '1 Market Street',
        'city' => 'Miami',
        'country' => 'US',
        'postal_code' => '33101',
        'state' => 'FL',
    ]);

    expect(array_keys($request->getParameters()))->toBe(['email', 'apiKey'])
        ->and($request->getData())->toBe(['email' => 'buyer@example.com']);
});

// ──────────────────────────────────────────────
//  sendData()
// ──────────────────────────────────────────────

it('creates the customer and reports the cus_ id as the reference', function () {
    $api = stripeCreateCustomerFakeApi(['id' => 'cus_created', 'object' => 'customer']);

    $response = stripeCreateCustomerRequest(['email' => 'buyer@example.com'])->send();

    expect($response)->toBeInstanceOf(CreateCustomerResponse::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('cus_created')
        ->and($response->getMessage())->toBeNull()
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/customers')
        ->and($api->calls[0]['params'])->toBe(['email' => 'buyer@example.com']);
});

/**
 * Unlike capture and refund, this call sends no `Idempotency-Key` — the source
 * passes no opts at all, which the package README states outright. Pinned so
 * the omission stays a decision: a retried create makes a second Stripe
 * Customer, and the repository's own link is what keeps that from happening
 * rather than Stripe's deduplication.
 */
it('sends no idempotency key, so duplicate protection rests on the caller', function () {
    $api = stripeCreateCustomerFakeApi(['id' => 'cus_created', 'object' => 'customer']);

    stripeCreateCustomerRequest(['email' => 'buyer@example.com', 'clientUniqueId' => 'customer-uuid-3'])->send();

    expect(implode("\n", $api->calls[0]['headers']))->not->toContain('Idempotency-Key');
});

/**
 * A rejected customer must come back as a failed response, because the
 * gateway's customer resolution reads `isSuccessful()` and raises its own
 * `RuntimeException` from it. An `ApiErrorException` escaping instead would
 * bypass that and surface as an unhandled error mid-payment.
 */
it('converts a Stripe API error into a failed response carrying the reason', function () {
    stripeCreateCustomerFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid email address']],
        400,
    );

    $response = stripeCreateCustomerRequest(['email' => 'not-an-email'])->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getTransactionReference())->toBeNull()
        ->and($response->getMessage())->toBe('Invalid email address');
});
