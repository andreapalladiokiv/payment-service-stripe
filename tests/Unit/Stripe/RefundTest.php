<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Stripe\Refund;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * {@see Refund} was entirely unexecuted.
 *
 * A refund is the one operation where a silently wrong amount cannot be
 * recovered from — the money has left. So the pins here are about the exact
 * figure and the exact target: the minor-unit integer must travel unchanged,
 * both inputs must be mandatory rather than defaulted, and the refund must be
 * addressed by PaymentIntent rather than by charge.
 *
 * `refund()` is exercised offline. Stripe's SDK resolves its HTTP client
 * through the static `ApiRequestor::setHttpClient()`, so a stub there answers
 * the `StripeClient` built inside it; the `afterEach` restores the real curl
 * client.
 */
function stripeRefundFakeApi(array $body, int $status = 200): object
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

function stripeRefund(array $parameters = []): Refund
{
    return new Refund(
        new StripeSettings($parameters['apiKey'] ?? 'sk_test_fake'),
        new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: $parameters['transactionReference'] ?? 'pi_refunded',
            amount: $parameters['money'] ?? new Money(2500, new Currency('USD')),
            clientUniqueId: $parameters['clientUniqueId'] ?? null,
        ),
    );
}

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

// ──────────────────────────────────────────────
//  payload()
// ──────────────────────────────────────────────

/*
 * `it('requires both the amount and the payment reference')` lived here, with a row for each way
 * to omit one. Both are constructor arguments on {@see RefundCommand}, so none of its three rows
 * describes an operation that can be built. The concern behind it — that a missing amount would
 * refund the whole charge rather than the part asked for — is now answered by the type.
 */

/**
 * The minor unit passes through untouched — no rounding, no currency
 * conversion, no re-reading of the charge. The currency is deliberately absent
 * from the payload: Stripe refunds in the currency the intent was taken in,
 * and supplying a different one is how a refund silently becomes an FX
 * operation.
 */
it('builds refund data as the raw minor unit against the payment intent', function () {
    expect(stripeRefund()->payload())->toBe([
        'amount' => 2500,
        'payment_intent' => 'pi_refunded',
    ]);
});

// ──────────────────────────────────────────────
//  refund()
// ──────────────────────────────────────────────

/**
 * Refunds are created at `/v1/refunds` addressed by PaymentIntent, not by
 * charge. Addressing a charge still works at Stripe but bypasses the intent's
 * own refund accounting, so the endpoint and the key are both pinned.
 */
it('creates the refund against the payment intent', function () {
    $api = stripeRefundFakeApi(['id' => 're_1', 'object' => 'refund']);

    stripeRefund()->refund();

    expect($api->calls)->toHaveCount(1)
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/refunds')
        ->and($api->calls[0]['params'])->toBe([
            'payment_intent' => 'pi_refunded',
            'amount' => 2500,
        ]);
});

/**
 * Of every operation here this is the one where a retry without an idempotency
 * key costs real money twice, so the caller's `clientUniqueId` must reach
 * Stripe as a request option.
 */
it('sends the caller idempotency key so a retried refund cannot pay out twice', function () {
    $api = stripeRefundFakeApi(['id' => 're_1', 'object' => 'refund']);

    stripeRefund(['clientUniqueId' => 'refund-uuid-9'])->refund();

    expect($api->calls[0]['headers'])->toContain('Idempotency-Key: refund-uuid-9');
});

/**
 * The reference is the refund's own `re_…` id, not the `pi_…` it was taken
 * against. Downstream reconciles refunds by that id, and echoing the payment
 * intent back would make two refunds on one payment indistinguishable.
 */
it('reports the refund id rather than the payment intent as the reference', function () {
    stripeRefundFakeApi(['id' => 're_created', 'object' => 'refund']);

    $result = stripeRefund()->refund();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('re_created')
        ->and($result->message)->toBeNull();
});

/**
 * A declined refund — insufficient platform balance, a charge already fully
 * refunded — is an outcome the caller records, so it must arrive as a failed
 * result rather than as a thrown `ApiErrorException`.
 */
it('converts a Stripe API error into a failed result carrying the reason', function () {
    stripeRefundFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'Charge has already been refunded.']],
        400,
    );

    $result = stripeRefund()->refund();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toBe('Charge has already been refunded.');
});

/**
 * A refund never converts currency, so it must never claim to have. Pinned
 * because {@see \Techork\PaymentService\Gateway\Contract\GatewayResult} carries
 * a `convertedAmount` slot for every operation, and this one is what leaves it
 * alone.
 */
it('never reports a converted amount', function () {
    stripeRefundFakeApi(['id' => 're_1', 'object' => 'refund']);

    expect(stripeRefund()->refund()->convertedAmount)->toBeNull();
});
