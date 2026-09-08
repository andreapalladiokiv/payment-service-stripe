<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Stripe\Capture;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * {@see Capture} was entirely unexecuted. It is the only Stripe operation
 * whose parameter name changes on the way out — `payload()` produces `amount`,
 * while the Stripe API wants `amount_to_capture` — and the only one where
 * omitting a key is a distinct instruction rather than a missing field: no
 * `amount_to_capture` means "capture the full authorization". Both are pinned
 * below, because sending a zero or a stale amount where nothing should be sent
 * captures the wrong sum of money.
 *
 * Nothing here reaches the network. Stripe's SDK resolves its HTTP client
 * through the static `ApiRequestor::setHttpClient()`, so a stub there answers
 * every call the `StripeClient` built inside `capture()` makes; the `afterEach`
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

function stripeCapture(array $parameters = []): Capture
{
    return new Capture(
        new StripeSettings($parameters['apiKey'] ?? 'sk_test_fake'),
        new CaptureCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: $parameters['transactionReference'] ?? 'pi_captured',
            amount: $parameters['money'] ?? new Money(1000, new Currency('USD')),
            clientUniqueId: $parameters['clientUniqueId'] ?? null,
        ),
    );
}

/**
 * A PaymentIntent whose settlement currency differs from what the cardholder
 * paid, with `latest_charge.balance_transaction` expanded the way `capture()`
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
//  payload()
// ──────────────────────────────────────────────

/*
 * Three tests lived here and describe states that no longer exist.
 *
 * Two of them — a capture with no reference, and a capture with no amount — were reachable only
 * because both arrived as optional keys in a parameter array. {@see CaptureCommand} types them as
 * constructor arguments, so `validate()` and the `isset()` that guarded the amount have nothing
 * left to guard.
 *
 * The third said that a full capture sends no `amount_to_capture`. It never did in production: the
 * router passed `money` on every capture, so the key was always present. The test was describing
 * the parameter array's freedom, not the acquirer's behaviour.
 */

/**
 * The minor-unit integer is taken from Money as-is. No currency travels with
 * it — Stripe captures in the currency the intent was authorized in, and
 * sending a converted figure would capture the wrong amount.
 */
it('carries the partial capture amount as the raw minor unit', function () {
    expect(stripeCapture(['money' => new Money(1234, new Currency('USD'))])->payload())
        ->toBe(['payment_intent' => 'pi_captured', 'amount' => 1234]);
});

// ──────────────────────────────────────────────
//  capture()
// ──────────────────────────────────────────────

/**
 * The rename is the whole risk of this method: `amount` in our payload becomes
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

    stripeCapture(['money' => new Money(1000000, new Currency('JPY'))])->capture();

    expect($api->calls)->toHaveCount(1)
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/payment_intents/pi_captured/capture')
        ->and($api->calls[0]['params'])->toBe([
            'expand' => ['latest_charge.balance_transaction'],
            'amount_to_capture' => 1000000,
        ]);
});

/**
 * A capture is not safe to repeat: a retry without an idempotency key can
 * capture twice. The key comes from the caller's `clientUniqueId`, so this
 * pins that the operation actually forwards it as a Stripe request option
 * rather than only holding it.
 */
it('sends the caller idempotency key as a Stripe request option', function () {
    $api = stripeCaptureFakeApi(stripeCaptureIntentBody());

    stripeCapture(['clientUniqueId' => 'capture-uuid-1'])->capture();

    expect($api->calls[0]['headers'])->toContain('Idempotency-Key: capture-uuid-1');
});

it('reports the captured PaymentIntent id as the transaction reference', function () {
    stripeCaptureFakeApi(stripeCaptureIntentBody());

    $result = stripeCapture()->capture();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('pi_captured')
        ->and($result->message)->toBeNull();
});

/**
 * The settled amount is what reconciliation posts, so a capture on a
 * cross-currency intent must carry it out of the response. 6121 in USD, taken
 * from the balance transaction rather than from the amount the cardholder was
 * charged.
 */
it('surfaces the FX-settled amount when presentment and settlement currencies differ', function () {
    stripeCaptureFakeApi(stripeCaptureIntentBody('jpy', 'usd'));

    expect(stripeCapture()->capture()->convertedAmount)->toEqual(new Money(6121, new Currency('USD')));
});

/**
 * No conversion must read as no FX signal, not as a conversion whose figures
 * happen to match — a Money here would have downstream record a rate for a
 * charge that never crossed a currency.
 */
it('reports no converted amount when the charge settled in its own currency', function () {
    stripeCaptureFakeApi(stripeCaptureIntentBody('usd', 'usd'));

    expect(stripeCapture()->capture()->convertedAmount)->toBeNull();
});

/**
 * An already-captured or expired authorization is an ordinary outcome, not an
 * exception for callers to handle: the caller folds a failed result into an
 * event, whereas a thrown `ApiErrorException` would escape as an unhandled
 * error. The message is kept because it is the only explanation the operator
 * gets.
 */
it('converts a Stripe API error into a failed result carrying the reason', function () {
    stripeCaptureFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'PaymentIntent already captured']],
        400,
    );

    $result = stripeCapture()->capture();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toBe('PaymentIntent already captured');
});

/**
 * A failed capture must not report a converted amount: a rate read off a
 * capture that took nothing would be recorded against a payment that never
 * settled.
 */
it('reports no converted amount on a failed capture', function () {
    stripeCaptureFakeApi(['error' => ['type' => 'api_error', 'message' => 'boom']], 500);

    expect(stripeCapture()->capture()->convertedAmount)->toBeNull();
});
