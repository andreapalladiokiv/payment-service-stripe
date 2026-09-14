<?php

declare(strict_types=1);

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Gateway\Command\DisputeCaseQuery;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Stripe\Dispute\DisputeCaseRead;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * `GET /v1/disputes/:id`, read into the two facts an operator acts on.
 *
 * The payloads come from the recorded documentation samples under `tests/Fixtures/Disputes/` — F0
 * committed `disputes.get.doc-sample.json` and the ingestion side added the rest — rather than from
 * bodies invented here, because the point of this read is that Stripe's real shapes are what they
 * are: `payment_method_details.card` nested behind `type`, `due_by` as unix seconds, and the status
 * a string the SDK's own docblock does not enumerate.
 *
 * What is pinned, beyond the happy path: that a case already with the network offers nothing, that
 * an inquiry is not offered the close (its closed status is an expiry, not a concession — see
 * {@see DisputeCaseRead}), and that a payload omitting the deadline on a case we must answer is
 * refused rather than read as "nothing to do".
 */
function stripeDisputeCaseFakeApi(array $body, int $status = 200): object
{
    $client = new class($body, $status) implements ClientInterface
    {
        /** @var list<array{url: string, params: array<string, mixed>}> */
        public array $calls = [];

        public function __construct(private array $body, private int $status) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->calls[] = ['url' => (string) $absUrl, 'params' => (array) $params];

            return [json_encode($this->body), $this->status, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

/** The recorded GET sample: a Visa fraud chargeback, waiting on us, due 2025-01-30. */
function stripeDisputeCaseFixture(): array
{
    $path = __DIR__.'/../../../Fixtures/Disputes/disputes.get.doc-sample.json';
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

function stripeDisputeCaseRead(string $reference = 'dp_1QkDisputeCaseAlpha'): DisputeCaseRead
{
    return new DisputeCaseRead(
        new StripeSettings('sk_test_fake'),
        new DisputeCaseQuery(gatewayId: GatewayId::generate(), disputeReference: $reference),
    );
}

it('reads a case the provider is waiting on, with the pair and the deadline it stated', function () {
    $api = stripeDisputeCaseFakeApi(stripeDisputeCaseFixture());

    $reading = stripeDisputeCaseRead()->read();

    expect($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/disputes/dp_1QkDisputeCaseAlpha')
        ->and($api->calls[0]['params'])->toBe([])
        ->and($reading->awaitingResponse)->toBeTrue()
        ->and($reading->cardBrand)->toBe('visa')
        ->and($reading->reasonCode)->toBe('10.4')
        ->and($reading->concedable)->toBeTrue()
        // `due_by` is unix seconds in the payload and an instant here — the same conversion the
        // ingestion side makes of the same field.
        ->and($reading->respondBy?->getTimestamp())->toBe(1738195199);
});

/** The network has the case; nothing is waiting on us, so nothing is offered. */
it('reports a case under review as not waiting on us', function () {
    stripeDisputeCaseFakeApi([...stripeDisputeCaseFixture(), 'status' => 'under_review']);

    $reading = stripeDisputeCaseRead()->read();

    expect($reading->awaitingResponse)->toBeFalse()
        ->and($reading->concedable)->toBeFalse()
        // Still read: an operator looking at the case is owed the window the provider states, and
        // only the two facts above decide what may be done about it.
        ->and($reading->respondBy)->not->toBeNull();
});

it('reports a decided case as not waiting on us', function () {
    stripeDisputeCaseFakeApi([...stripeDisputeCaseFixture(), 'status' => 'won']);

    expect(stripeDisputeCaseRead()->read()->awaitingResponse)->toBeFalse();
});

/**
 * An inquiry is waiting on us — it has its own response window — but `close` is documented for a
 * dispute, and the plan reads an inquiry's closed status as an expiry rather than as a concession.
 * Withholding the irreversible call is the safe direction: an operator can still do it by hand.
 */
it('waits on an inquiry but withholds the concession', function () {
    $inquiry = stripeDisputeCaseFixture();
    $inquiry['status'] = 'warning_needs_response';
    $inquiry['payment_method_details']['card']['case_type'] = 'inquiry';
    $inquiry['payment_method_details']['card']['brand'] = 'amex';

    stripeDisputeCaseFakeApi($inquiry);

    $reading = stripeDisputeCaseRead()->read();

    expect($reading->awaitingResponse)->toBeTrue()
        ->and($reading->concedable)->toBeFalse()
        ->and($reading->cardBrand)->toBe('amex');
});

/**
 * The pair is looked up verbatim, so a payload that states no brand leaves the requirements
 * template unresolved rather than guessed — the case is still surfaced.
 */
it('reads a payload with no card details as awaiting, with no pair and no concession', function () {
    $payload = stripeDisputeCaseFixture();
    unset($payload['payment_method_details']);

    stripeDisputeCaseFakeApi($payload);

    $reading = stripeDisputeCaseRead()->read();

    expect($reading->awaitingResponse)->toBeTrue()
        ->and($reading->cardBrand)->toBeNull()
        ->and($reading->reasonCode)->toBeNull()
        ->and($reading->concedable)->toBeFalse();
});

/**
 * A case the provider says it is still waiting on has a deadline — that is what waiting means — and
 * a reading that dropped it would hand up a case with no response task and no way to say why. The
 * value object refuses it rather than inventing a date.
 */
it('refuses a payload that omits the deadline on a case it is waiting on', function () {
    $payload = stripeDisputeCaseFixture();
    $payload['evidence_details']['due_by'] = null;

    stripeDisputeCaseFakeApi($payload);

    expect(fn () => stripeDisputeCaseRead()->read())
        ->toThrow(InvalidArgumentException::class, 'must carry the deadline it is waiting until');
});

/**
 * The read has no failed-result channel: the role answers with a reading, so a caller that could not
 * be told what a case is waiting for has not learned that it is waiting for nothing.
 */
it('throws when the provider could not be asked about the case', function () {
    stripeDisputeCaseFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'No such dispute: dp_1QkDisputeCaseAlpha']],
        404,
    );

    expect(fn () => stripeDisputeCaseRead()->read())
        ->toThrow(RuntimeException::class, 'dp_1QkDisputeCaseAlpha')
        ->toThrow(RuntimeException::class, 'No such dispute');
});

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});
