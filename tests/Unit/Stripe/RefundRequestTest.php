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
use Techork\PaymentService\Stripe\RefundRequest;
use Techork\PaymentService\Stripe\RefundResponse;

/**
 * {@see RefundRequest} was entirely unexecuted.
 *
 * A refund is the one operation where a silently wrong amount cannot be
 * recovered from — the money has left. So the pins here are about the exact
 * figure and the exact target: the minor-unit integer must travel unchanged,
 * both inputs must be mandatory rather than defaulted, and the refund must be
 * addressed by PaymentIntent rather than by charge.
 *
 * `sendData()` is exercised offline. Stripe's SDK resolves its HTTP client
 * through the static `ApiRequestor::setHttpClient()`, so a stub there answers
 * the `StripeClient` built inside `sendData()`; the `afterEach` restores the
 * real curl client.
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

function stripeRefundRequest(array $parameters = []): RefundRequest
{
    $request = new RefundRequest(new OmnipayClient, new HttpRequest);
    $request->initialize($parameters + [
        'apiKey' => 'sk_test_fake',
        'transactionReference' => 'pi_refunded',
        'money' => new Money(2500, new Currency('USD')),
    ]);

    return $request;
}

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

// ──────────────────────────────────────────────
//  getData()
// ──────────────────────────────────────────────

/**
 * Both inputs are mandatory, and separately so. Stripe would happily accept a
 * refund with no amount — it refunds the full charge — so a missing `money`
 * that fell through to a default would return the entire payment instead of
 * the part that was asked for.
 */
it('requires both the amount and the payment reference', function (array $parameters) {
    $request = new RefundRequest(new OmnipayClient, new HttpRequest);
    $request->initialize($parameters + ['apiKey' => 'sk_test_fake']);

    $request->getData();
})->throws(InvalidRequestException::class)->with([
    'no amount' => [['transactionReference' => 'pi_1']],
    'no reference' => [fn () => ['money' => new Money(100, new Currency('USD'))]],
    'neither' => [[]],
]);

/**
 * The minor unit passes through untouched — no rounding, no currency
 * conversion, no re-reading of the charge. The currency is deliberately absent
 * from the payload: Stripe refunds in the currency the intent was taken in,
 * and supplying a different one is how a refund silently becomes an FX
 * operation.
 */
it('builds refund data as the raw minor unit against the payment intent', function () {
    expect(stripeRefundRequest()->getData())->toBe([
        'amount' => 2500,
        'payment_intent' => 'pi_refunded',
    ]);
});

// ──────────────────────────────────────────────
//  sendData()
// ──────────────────────────────────────────────

/**
 * Refunds are created at `/v1/refunds` addressed by PaymentIntent, not by
 * charge. Addressing a charge still works at Stripe but bypasses the intent's
 * own refund accounting, so the endpoint and the key are both pinned.
 */
it('creates the refund against the payment intent', function () {
    $api = stripeRefundFakeApi(['id' => 're_1', 'object' => 'refund']);

    stripeRefundRequest()->send();

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

    stripeRefundRequest(['clientUniqueId' => 'refund-uuid-9'])->send();

    expect($api->calls[0]['headers'])->toContain('Idempotency-Key: refund-uuid-9');
});

/**
 * The reference is the refund's own `re_…` id, not the `pi_…` it was taken
 * against. Downstream reconciles refunds by that id, and echoing the payment
 * intent back would make two refunds on one payment indistinguishable.
 */
it('reports the refund id rather than the payment intent as the reference', function () {
    stripeRefundFakeApi(['id' => 're_created', 'object' => 'refund']);

    $response = stripeRefundRequest()->send();

    expect($response)->toBeInstanceOf(RefundResponse::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('re_created')
        ->and($response->getMessage())->toBeNull();
});

/**
 * A declined refund — insufficient platform balance, a charge already fully
 * refunded — is an outcome the router records, so it must arrive as a failed
 * response rather than as a thrown `ApiErrorException`.
 */
it('converts a Stripe API error into a failed response carrying the reason', function () {
    stripeRefundFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'Charge has already been refunded.']],
        400,
    );

    $response = stripeRefundRequest()->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getTransactionReference())->toBeNull()
        ->and($response->getMessage())->toBe('Charge has already been refunded.');
});

/**
 * A refund never converts currency, so it must never claim to have. Pinned
 * because `RefundResponse` inherits `getConvertedAmount()` from the shared
 * {@see \Techork\PaymentService\Stripe\StripeResponse}, where the key simply
 * being absent is what makes the answer null.
 */
it('never reports a converted amount', function () {
    stripeRefundFakeApi(['id' => 're_1', 'object' => 'refund']);

    expect(stripeRefundRequest()->send()->getConvertedAmount())->toBeNull();
});
