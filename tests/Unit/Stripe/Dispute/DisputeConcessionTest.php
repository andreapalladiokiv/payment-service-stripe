<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Gateway\Command\DisputeConcessionCommand;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Stripe\Dispute\DisputeConcession;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * `POST /v1/disputes/:id/close` — the one call in this domain that cannot be taken back.
 *
 * The plan states the move it makes (`needs_response → lost`) and that requiring an operator's
 * confirmation before it is dispatched belongs to the application. What is left for this side is
 * narrower and it is what this file pins: nothing is conceded beyond what was asked, and a call
 * that did not close the case is never reported as one that did.
 *
 * The second point is not theoretical. The layer above records an accepted case from a successful
 * return, so a close answered with `under_review` — the network took the case anyway — reported as
 * success would book a concession nobody made. `warning_closed` is the sharpest case of the same
 * mistake: the plan reads it as an inquiry that sat 120 days without escalating, so recording it as
 * ours would turn an expiry into a decision we took.
 */
function stripeDisputeCloseFakeApi(array $body, int $status = 200): object
{
    $client = new class($body, $status) implements ClientInterface
    {
        /** @var list<array{url: string, params: array<string, mixed>, headers: array<int, string>}> */
        public array $calls = [];

        public function __construct(private array $body, private int $status) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->calls[] = ['url' => (string) $absUrl, 'params' => (array) $params, 'headers' => (array) $headers];

            return [json_encode($this->body), $this->status, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

function stripeDisputeConcession(?Money $partialAmount = null): DisputeConcession
{
    return new DisputeConcession(
        new StripeSettings('sk_test_fake'),
        new DisputeConcessionCommand(
            gatewayId: GatewayId::generate(),
            disputeReference: 'dp_1QkDisputeCaseAlpha',
            partialAmount: $partialAmount,
            clientUniqueId: 'dp_1QkDisputeCaseAlpha:close',
        ),
    );
}

// ──────────────────────────────────────────────
//  the close
// ──────────────────────────────────────────────

it('closes the case and reports it once Stripe answers lost', function () {
    $api = stripeDisputeCloseFakeApi(['id' => 'dp_1QkDisputeCaseAlpha', 'object' => 'dispute', 'status' => 'lost']);

    $result = stripeDisputeConcession()->concede();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('dp_1QkDisputeCaseAlpha')
        ->and($result->message)->toBeNull()
        ->and($api->calls)->toHaveCount(1)
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/disputes/dp_1QkDisputeCaseAlpha/close')
        // Closing takes no other parameter, and the key is what stops a retried job closing a case
        // that has already been given up.
        ->and($api->calls[0]['params'])->toBe([])
        ->and($api->calls[0]['headers'])->toContain('Idempotency-Key: dp_1QkDisputeCaseAlpha:close:dispute-close');
});

// ──────────────────────────────────────────────
//  what must not be reported as a concession
// ──────────────────────────────────────────────

/**
 * The network may keep the case. Reporting success would record an acceptance for a case that is
 * still being argued, which is the one thing this guard exists to prevent.
 */
it('refuses to report success when the case is not closed, and says nothing was recorded', function () {
    stripeDisputeCloseFakeApi(['id' => 'dp_1QkDisputeCaseAlpha', 'object' => 'dispute', 'status' => 'under_review']);

    expect(fn () => stripeDisputeConcession()->concede())
        ->toThrow(RuntimeException::class, 'under_review')
        ->toThrow(RuntimeException::class, 'dp_1QkDisputeCaseAlpha');
});

/**
 * The sharpest case: the plan reads `warning_closed` as an inquiry that expired without escalating,
 * so it is a different fact from a concession and must not be recorded as ours.
 */
it('does not read an expired inquiry as a concession', function () {
    stripeDisputeCloseFakeApi(['id' => 'dp_1', 'object' => 'dispute', 'status' => 'warning_closed']);

    expect(fn () => stripeDisputeConcession()->concede())->toThrow(RuntimeException::class, 'warning_closed');
});

it('refuses a response that states no status at all', function () {
    stripeDisputeCloseFakeApi(['id' => 'dp_1', 'object' => 'dispute']);

    expect(fn () => stripeDisputeConcession()->concede())->toThrow(RuntimeException::class, 'none stated');
});

// ──────────────────────────────────────────────
//  the amount, and the failure
// ──────────────────────────────────────────────

/**
 * Stripe's `close` concedes the whole case and takes no amount. Ignoring the amount and closing
 * anyway would give up the entire disputed sum in place of the part somebody decided to concede —
 * on a call that cannot be undone — so it is refused before anything is sent, with the marked
 * exception that says this is a wiring error rather than a provider that said no.
 */
it('refuses a partial amount instead of conceding the whole case', function () {
    $api = stripeDisputeCloseFakeApi(['id' => 'dp_1', 'object' => 'dispute', 'status' => 'lost']);

    expect(fn () => stripeDisputeConcession(new Money(500, new Currency('USD')))->concede())
        ->toThrow(UnsupportedOperation::class, 'concedePartially');

    expect($api->calls)->toBe([]);
});

/**
 * A case Stripe no longer lets us close arrives as an error rather than as a status, so it reaches
 * the caller as a failed result with Stripe's own words. The distinction the message has to keep:
 * the case is not conceded, and it is not known to be lost either.
 */
it('converts a Stripe API error into a failed result carrying the reason', function () {
    stripeDisputeCloseFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'This dispute cannot be closed']],
        400,
    );

    $result = stripeDisputeConcession()->concede();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toBe('This dispute cannot be closed');
});

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});
