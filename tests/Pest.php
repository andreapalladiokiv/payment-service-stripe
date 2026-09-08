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
