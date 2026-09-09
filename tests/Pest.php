<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

use Techork\PaymentService\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;

/**
 * Routes every Stripe SDK call to a canned JSON body and keeps what was sent. The recording is
 * the seam a payload test needs now that an operation performs its own call: there is no unsent
 * request object left to interrogate between building the body and putting it on the wire, so
 * what the gateway sent is read back off the transport instead. The transport is global to the
 * SDK, so a file that installs one restores `CurlClient::instance()` in an afterEach.
 *
 * `$body` answers the call under test; `$others` answers the rest by URL fragment, because a
 * performed operation makes more than one — a charge looks the payment method up before it
 * creates the intent, and the SDK builds a typed object out of whatever comes back, so one canned
 * body for every endpoint hands a Customer to code expecting a PaymentMethod.
 *
 * @param  array<string, array<string, mixed>>  $others
 * @return ArrayObject<int, array{url: string, params: array<string, mixed>}> filled as calls are made
 */
function fakeStripeHttp(array $body, int $status = 200, array $others = []): ArrayObject
{
    $sent = new ArrayObject;

    $client = new readonly class($body, $status, $others, $sent) implements ClientInterface
    {
        public function __construct(private array $body, private int $status, private array $others, private ArrayObject $sent) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->sent[] = ['url' => (string) $absUrl, 'params' => (array) $params];

            foreach ($this->others as $fragment => $body) {
                if (str_contains((string) $absUrl, $fragment)) {
                    return [json_encode($body), 200, []];
                }
            }

            return [json_encode($this->body), $this->status, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $sent;
}

/**
 * The body of the last call to an endpoint, or an empty array if it was never reached.
 *
 * @param  ArrayObject<int, array{url: string, params: array<string, mixed>}>  $sent
 * @return array<string, mixed>
 */
function lastStripeRequestTo(ArrayObject $sent, string $endpoint): array
{
    $matching = array_filter(iterator_to_array($sent), fn (array $call) => str_contains($call['url'], $endpoint));

    return $matching === [] ? [] : end($matching)['params'];
}

/**
 * A customer id for this suite's tests.
 */
function stripeSuiteCustomerId(string $id = '01920000-0000-7000-8000-00000000cafe'): CustomerId
{
    return CustomerId::fromString($id);
}

/**
 * The payer these tests hand to a command, complete, because a {@see Customer} has no partial
 * form — an id, a person and an address or nothing at all.
 *
 * That completeness is the change worth knowing about here. The id, the identity and the address
 * used to be three optional arguments a caller could supply any subset of, which is how a
 * provider-side customer came to be built out of whatever billing address rode along with the
 * payment. A test that wants to say "no payer" passes null, not a fragment.
 */
function stripeSuiteCustomer(
    ?CustomerId $id = null,
    string $firstName = 'Ada',
    string $lastName = 'Lovelace',
    ?Email $email = null,
    ?PhoneNumber $phone = null,
    ?BillingAddress $address = null,
): Customer {
    return new Customer(
        id: $id ?? stripeSuiteCustomerId(),
        identity: new CustomerIdentity($firstName, $lastName, $email, $phone),
        billingAddress: $address ?? new BillingAddress(
            line: '1 Main St',
            city: 'New York',
            country: new Country('US'),
            postalCode: '10001',
        ),
    );
}

/**
 * The one customer a command now takes, assembled from the option keys these tests have used all
 * along.
 *
 * `billingAddress`, `customerId` and `customerIdentity` were three separate command fields and
 * are one. The keys stay because what each test is *saying* has not changed — "billed here", "for
 * this customer", "who is this person" — and rewriting every call site to say it a new way would
 * bury the change that matters in the change that does not.
 *
 * Naming any one of them yields a whole customer, which is the design: an address with nobody
 * attached to it is not expressible any more. A test that means "no payer at all" names none of
 * the three and gets null.
 *
 * @param  array<string, mixed>  $options
 */
function stripeSuiteCustomerFrom(array $options): ?Customer
{
    if (array_key_exists('customer', $options)) {
        return $options['customer'];
    }

    $named = ['billingAddress', 'customerId', 'customerIdentity'];
    if (! array_filter($named, static fn (string $k): bool => ($options[$k] ?? null) !== null)) {
        return null;
    }

    /** @var ?CustomerIdentity $identity */
    $identity = $options['customerIdentity'] ?? null;

    return stripeSuiteCustomer(
        id: $options['customerId'] ?? null,
        firstName: $identity->firstName ?? 'Ada',
        lastName: $identity->lastName ?? 'Lovelace',
        email: $identity->email ?? null,
        phone: $identity->phone ?? null,
        address: $options['billingAddress'] ?? null,
    );
}
