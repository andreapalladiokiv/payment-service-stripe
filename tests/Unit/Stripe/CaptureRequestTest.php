<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Stripe\CaptureRequest;
use Techork\PaymentService\Stripe\CaptureResponse;

/**
 * {@see CaptureRequest} was entirely unexecuted. It is the only Stripe request
 * whose parameter name changes on the way out — `getData()` produces `amount`,
 * while the Stripe API wants `amount_to_capture` — and the only one where
 * omitting a key is a distinct instruction rather than a missing field: no
 * `amount_to_capture` means "capture the full authorization". Both are pinned
 * below, because sending a zero or a stale amount where nothing should be sent
 * captures the wrong sum of money.
 *
 * Nothing here reaches the network. Stripe's SDK resolves its HTTP client
 * through the static `ApiRequestor::setHttpClient()`, so a stub there answers
 * every call the `StripeClient` built inside `sendData()` makes; the `afterEach`
 * puts the real curl client back so no later test inherits the stub.
 */
function stripeCaptureFakeApi(array $body, int $status = 200): object
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

function stripeCaptureRequest(array $parameters = []): CaptureRequest
{
    $request = new CaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize($parameters + ['apiKey' => 'sk_test_fake', 'transactionReference' => 'pi_captured']);

    return $request;
}

/**
 * A PaymentIntent whose settlement currency differs from what the cardholder
 * paid, with `latest_charge.balance_transaction` expanded the way `sendData()`
 * asks for it. ¥1,000,000 presented, $6,121 received.
 */
function stripeCaptureIntentBody(string $presentment = 'jpy', string $settlement = 'usd'): array
{
    return [
        'id' => 'pi_captured',
        'object' => 'payment_intent',
        'currency' => $presentment,
        'latest_charge' => [
            'id' => 'ch_1',
            'object' => 'charge',
            'currency' => $presentment,
            'balance_transaction' => [
                'id' => 'txn_1',
                'object' => 'balance_transaction',
                'amount' => 6121,
                'currency' => $settlement,
            ],
        ],
    ];
}

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

// ──────────────────────────────────────────────
//  getData()
// ──────────────────────────────────────────────

/**
 * There is nothing to capture without the authorization's id, and omnipay's
 * `validate()` is the only thing standing between a missing reference and a
 * call to `/v1/payment_intents//capture`.
 */
it('refuses to build capture data without the authorization reference', function () {
    $request = new CaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize(['apiKey' => 'sk_test_fake']);

    $request->getData();
})->throws(InvalidRequestException::class);

/**
 * Absence is the instruction for a full capture. If `amount` appeared here as
 * a null or a zero, `sendData()`'s `isset()` would still add
 * `amount_to_capture` for the zero and Stripe would capture nothing at all.
 */
it('omits the amount entirely when no partial capture was asked for', function () {
    expect(stripeCaptureRequest()->getData())->toBe(['payment_intent' => 'pi_captured']);
});

/**
 * The minor-unit integer is taken from Money as-is. No currency travels with
 * it — Stripe captures in the currency the intent was authorized in, and
 * sending a converted figure would capture the wrong amount.
 */
it('carries the partial capture amount as the raw minor unit', function () {
    expect(stripeCaptureRequest(['money' => new Money(1234, new Currency('USD'))])->getData())
        ->toBe(['payment_intent' => 'pi_captured', 'amount' => 1234]);
});

// ──────────────────────────────────────────────
//  sendData()
// ──────────────────────────────────────────────

/**
 * The rename is the whole risk of this method: `amount` in our data becomes
 * `amount_to_capture` on the wire. Stripe ignores unknown-but-similar keys on
 * some endpoints, so getting it wrong here captures the full authorization
 * silently instead of failing.
 *
 * The `expand` is asserted alongside it because
 * {@see \Techork\PaymentService\Stripe\Concern\ExtractsConvertedAmount} reads
 * `latest_charge.balance_transaction` and returns null when it was not
 * expanded — i.e. dropping the expand loses the settled amount without any
 * error.
 */
it('renames the amount to amount_to_capture and expands the balance transaction', function () {
    $api = stripeCaptureFakeApi(stripeCaptureIntentBody());

    stripeCaptureRequest(['money' => new Money(1000000, new Currency('JPY'))])->send();

    expect($api->calls)->toHaveCount(1)
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/payment_intents/pi_captured/capture')
        ->and($api->calls[0]['params'])->toBe([
            'expand' => ['latest_charge.balance_transaction'],
            'amount_to_capture' => 1000000,
        ]);
});

it('sends no amount_to_capture on a full capture', function () {
    $api = stripeCaptureFakeApi(stripeCaptureIntentBody());

    stripeCaptureRequest()->send();

    expect($api->calls[0]['params'])->toBe(['expand' => ['latest_charge.balance_transaction']]);
});

/**
 * A capture is not safe to repeat: a retry without an idempotency key can
 * capture twice. The key comes from the caller's `clientUniqueId`, so this
 * pins that the request actually forwards it as a Stripe request option
 * rather than only storing it.
 */
it('sends the caller idempotency key as a Stripe request option', function () {
    $api = stripeCaptureFakeApi(stripeCaptureIntentBody());

    stripeCaptureRequest(['clientUniqueId' => 'capture-uuid-1'])->send();

    expect($api->calls[0]['headers'])->toContain('Idempotency-Key: capture-uuid-1');
});

it('reports the captured PaymentIntent id as the transaction reference', function () {
    stripeCaptureFakeApi(stripeCaptureIntentBody());

    $response = stripeCaptureRequest()->send();

    expect($response)->toBeInstanceOf(CaptureResponse::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('pi_captured')
        ->and($response->getMessage())->toBeNull();
});

/**
 * The settled amount is what reconciliation posts, so a capture on a
 * cross-currency intent must carry it out of the response. 6121 in USD, taken
 * from the balance transaction rather than from the amount the cardholder was
 * charged.
 */
it('surfaces the FX-settled amount when presentment and settlement currencies differ', function () {
    stripeCaptureFakeApi(stripeCaptureIntentBody('jpy', 'usd'));

    $converted = stripeCaptureRequest()->send()->getConvertedAmount();

    expect($converted)->toEqual(new Money(6121, new Currency('USD')));
});

/**
 * No conversion must read as no FX signal, not as a conversion whose figures
 * happen to match — a Money here would have downstream record a rate for a
 * charge that never crossed a currency.
 */
it('reports no converted amount when the charge settled in its own currency', function () {
    stripeCaptureFakeApi(stripeCaptureIntentBody('usd', 'usd'));

    expect(stripeCaptureRequest()->send()->getConvertedAmount())->toBeNull();
});

/**
 * An already-captured or expired authorization is an ordinary outcome, not an
 * exception for callers to handle: the router folds a failed response into a
 * result, whereas a thrown `ApiErrorException` would escape as an unhandled
 * error. The message is kept because it is the only explanation the operator
 * gets.
 */
it('converts a Stripe API error into a failed response carrying the reason', function () {
    stripeCaptureFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'PaymentIntent already captured']],
        400,
    );

    $response = stripeCaptureRequest()->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getTransactionReference())->toBeNull()
        ->and($response->getMessage())->toBe('PaymentIntent already captured');
});

/**
 * A failed capture must not report a converted amount. `isSuccessful()` reads
 * only the reference, so a stray `converted_amount` on the error branch would
 * be readable on a response that captured nothing.
 */
it('reports no converted amount on a failed capture', function () {
    stripeCaptureFakeApi(['error' => ['type' => 'api_error', 'message' => 'boom']], 500);

    expect(stripeCaptureRequest()->send()->getConvertedAmount())->toBeNull();
});
