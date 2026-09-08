<?php

declare(strict_types=1);

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\Stripe\CreateCustomer;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * {@see CreateCustomer} was entirely unexecuted.
 *
 * It is the operation that decides what a Stripe Customer record contains, and
 * `payload()` is built out of nested `array_filter` — a construct where the
 * difference between "sent as null", "sent as empty" and "not sent at all" is
 * invisible in the source and decisive at the API. Stripe treats an explicitly
 * sent empty `address` as an instruction to clear the one it holds, so which
 * keys survive the filter is the behaviour worth pinning.
 *
 * `create()` runs offline: Stripe's SDK resolves its HTTP client through the
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

function stripeCreateCustomer(array $parameters = []): CreateCustomer
{
    return new CreateCustomer(
        new StripeSettings($parameters['apiKey'] ?? 'sk_test_fake'),
        $parameters['identity'] ?? (isset($parameters['email'])
            ? new CustomerIdentity('Test', 'User', new Email($parameters['email']))
            : null),
        $parameters['billingAddress'] ?? null,
    );
}

/**
 * The address the operation now reads, assembled from the same loose keys these tests used to
 * write straight into the parameter bag.
 *
 * The bag was not a shortcut: `address`, `city`, `country`, `postal_code` and `state` had no
 * setters, so omnipay dropped every one and the bag was the only way to reach the code at all.
 * That was the defect. The address is a constructor argument now, so an absent key stays absent
 * rather than arriving as an empty string — which is what `array_filter` in the operation removes.
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
//  payload()
// ──────────────────────────────────────────────

/**
 * The address block must vanish completely, not arrive as an empty array.
 * `customers.create` with `address: {}` is a valid call that blanks the
 * address on the record, so an empty nested array is not a harmless no-op —
 * it is a destructive update. The `?: null` in the source is what collapses it,
 * and this is the assertion that says so.
 */
it('omits the address block entirely when no address part is known', function () {
    expect(stripeCreateCustomer(['email' => 'buyer@example.com'])->payload())
        ->toBe(['name' => 'Test User', 'email' => 'buyer@example.com']);
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

    $data = stripeCreateCustomer([
        'email' => $keys['email'],
        'billingAddress' => stripeCreateCustomerAddress($keys),
    ])->payload();

    expect($data)->toBe([
        'name' => 'Test User',
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

    $data = stripeCreateCustomer([
        'email' => 'buyer@example.com',
        'billingAddress' => stripeCreateCustomerAddress($keys),
    ])->payload();

    expect($data['address'])->toBe(['city' => 'Berlin', 'country' => 'DE']);
});

/**
 * Nothing known about the person produces an empty payload, and Stripe creates a customer from
 * it anyway — `customers.create` requires no field at all, which is why the email gate that used
 * to guard this call was removable.
 *
 * Reachable only from a host calling {@see \Techork\PaymentService\Stripe\StripeGateway::createCustomer()}
 * with nothing: the routed operation refuses an unnamed customer before it gets here, and the
 * identity it passes always carries a name.
 */
it('produces an empty payload when nothing at all is known about the person', function () {
    expect(stripeCreateCustomer()->payload())->toBe([]);
});

/**
 * The identity answers and the address is the fallback — the same order every provider here now
 * reads them in.
 *
 * That order is the change. The address used to be the ONLY source, because it was where the
 * payer's name and email were kept, one copy per card; resolution assembled a person out of
 * whichever address happened to ride along with the payment being made. So the assertion worth
 * having is not that an identity works, it is that it WINS: two different people are named here
 * and the customer is the one the caller passed.
 */
it('takes the person from the identity and the address from the address', function () {
    $data = stripeCreateCustomer([
        'identity' => new CustomerIdentity('Ada', 'Lovelace', new Email('ada@example.com')),
        'billingAddress' => new BillingAddress(
            firstName: 'Whoever',
            lastName: 'Paid',
            line: '1 Market Street',
            city: 'Miami',
            country: new Country('US'),
            postalCode: '33101',
            email: new Email('whoever@example.com'),
        ),
    ])->payload();

    expect($data['name'])->toBe('Ada Lovelace')
        ->and($data['email'])->toBe('ada@example.com')
        // The address is not replaced by the identity, and must not be: it is a separate fact,
        // and at more than one provider the same object carries the AVS payload.
        ->and($data['address'])->toBe([
            'line1' => '1 Market Street',
            'city' => 'Miami',
            'country' => 'US',
            'postal_code' => '33101',
        ]);
});

/**
 * With nobody named, the address still answers. It is the honest reading of what we have — the
 * address is where the payer's name and email have been kept all along — and it is what keeps a
 * host that has not adopted customers yet working exactly as it did.
 */
it('falls back to the address when no identity was passed', function () {
    $data = stripeCreateCustomer([
        'billingAddress' => new BillingAddress(
            firstName: 'Whoever',
            lastName: 'Paid',
            line: '1 Market Street',
            city: 'Miami',
            country: new Country('US'),
            postalCode: '33101',
            email: new Email('whoever@example.com'),
        ),
    ])->payload();

    expect($data['name'])->toBe('Whoever Paid')
        ->and($data['email'])->toBe('whoever@example.com');
});

/*
 * Two tests lived here and describe a class that no longer exists.
 *
 * `it('round-trips the email through its own accessor')` built the operation with omnipay's
 * `(ClientInterface, HttpRequest)` pair and drove `setEmail()`. There is no such constructor and
 * no such setter: the email is a constructor argument.
 *
 * `it('cannot receive an address as a request option because no setter accepts one')` pinned the
 * defect those setters caused — an address handed in as an option was silently discarded, so
 * every Stripe Customer carried an email and nothing else. It asserted through `getParameters()`,
 * which was the parameter bag. There is no bag, the address arrives typed, and the two tests
 * above assert that it reaches the payload.
 */

// ──────────────────────────────────────────────
//  create()
// ──────────────────────────────────────────────

it('creates the customer and reports the cus_ id as the reference', function () {
    $api = stripeCreateCustomerFakeApi(['id' => 'cus_created', 'object' => 'customer']);

    $result = stripeCreateCustomer(['email' => 'buyer@example.com'])->create();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('cus_created')
        ->and($result->message)->toBeNull()
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/customers')
        ->and($api->calls[0]['params'])->toBe(['name' => 'Test User', 'email' => 'buyer@example.com']);
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

    stripeCreateCustomer(['email' => 'buyer@example.com'])->create();

    expect(implode("\n", $api->calls[0]['headers']))->not->toContain('Idempotency-Key');
});

/**
 * A rejected customer must come back as a failed result, because
 * {@see \Techork\PaymentService\Stripe\StripeGateway::registerCustomer()} reads `success` and
 * turns it into a failed `RegistrationResult`. An `ApiErrorException` escaping instead would
 * bypass that and surface as an unhandled error.
 */
it('converts a Stripe API error into a failed result carrying the reason', function () {
    stripeCreateCustomerFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid email address']],
        400,
    );

    // A valid email on our side; Stripe is the one rejecting it. `Email` refuses a malformed
    // address at construction, so the invalid value cannot reach this call from here at all —
    // which is the point of the value object and why the rejection has to be staged upstream.
    $result = stripeCreateCustomer(['email' => 'buyer@example.com'])->create();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toBe('Invalid email address');
});
