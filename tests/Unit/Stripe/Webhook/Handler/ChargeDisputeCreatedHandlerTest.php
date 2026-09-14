<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Stripe\Util\Util;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeResolution;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\UnmatchedDispute;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeDisputeCreatedHandler;

/**
 * `charge.dispute.created` — the first delivery of a case.
 *
 * Payloads are built inline through the helper below, in the style
 * {@see ChargeUpdatedHandlerTest} set: the shape being asserted is the handler's reading of
 * Stripe's fields, and a factory that starts from a realistic dispute and takes overrides reads
 * better than a fixture per case. The raw-byte fixtures in `tests/Fixtures/Disputes/` are for the
 * wire-shape cases — see `DisputeIngestionContractTest`.
 *
 * Pest helpers are global, so every function in this file is named for this file.
 */
function disputeCreatedEvent(array $dispute = []): object
{
    return Util::convertToStripeObject([
        'id' => 'evt_dispute_created_1',
        'object' => 'event',
        'type' => 'charge.dispute.created',
        'created' => 1737004800,
        'data' => ['object' => array_replace([
            'id' => 'dp_case_alpha',
            'object' => 'dispute',
            'amount' => 1500,
            'currency' => 'usd',
            'payment_intent' => 'pi_shared',
            'reason' => 'fraudulent',
            'status' => 'needs_response',
            'payment_method_details' => ['card' => ['brand' => 'visa']],
            'evidence_details' => ['due_by' => 1738195199],
        ], $dispute)],
    ], []);
}

/** The internal aggregate id the resolver hands back for `pi_shared`. */
function disputeCreatedInternalId(): string
{
    return '01929fa5-0000-7000-8000-0000000000d1';
}

/**
 * A recorder that records what it was told. Real rather than mocked because the assertions are
 * about the *snapshot's* fields, and a Mockery expectation on a DTO argument can only say that
 * something was passed, not what was in it.
 *
 * The anonymous class declares more than the interface does — `observed`, `resolved`, `unmatched` —
 * which a caller reaching through the interface could not see. That is on purpose: this helper's
 * return type is the object it built, so the tests read those properties directly.
 */
function disputeCreatedRecorder(RecorderOutcome $outcome = RecorderOutcome::Applied): GatewayDisputeRecorder
{
    return new class($outcome) implements GatewayDisputeRecorder
    {
        /** @var list<array{0: GatewayId, 1: string, 2: DisputeSnapshot}> */
        public array $observed = [];

        /** @var list<array{0: GatewayId, 1: string, 2: object}> */
        public array $resolved = [];

        public int $unmatched = 0;

        public function __construct(private RecorderOutcome $outcome) {}

        public function onDisputeObserved(GatewayId $gatewayId, string $paymentIntentId, DisputeSnapshot $snapshot): RecorderOutcome
        {
            $this->observed[] = [$gatewayId, $paymentIntentId, $snapshot];

            return $this->outcome;
        }

        public function onDisputeResolved(GatewayId $gatewayId, string $disputeRef, DisputeResolution $resolution): RecorderOutcome
        {
            $this->resolved[] = [$gatewayId, $disputeRef, $resolution];

            return $this->outcome;
        }

        public function onUnmatchedDispute(GatewayId $gatewayId, UnmatchedDispute $case): RecorderOutcome
        {
            $this->unmatched++;

            return $this->outcome;
        }
    };
}

/** A resolver that knows one reference and nothing else. */
function disputeCreatedResolver(?string $internalId = null): TransactionIdResolver
{
    return new class($internalId) implements TransactionIdResolver
    {
        public function __construct(private ?string $internalId) {}

        public function resolvePaymentIntent(GatewayId $gatewayId, string $reference): ?string
        {
            return $this->internalId;
        }

        public function resolveRefund(GatewayId $gatewayId, string $reference): ?string
        {
            return null;
        }
    };
}

it('reports the case as observed, with every field Stripe stated and none of the ones it did not', function () {
    $gatewayId = GatewayId::generate();
    $recorder = disputeCreatedRecorder();

    $handler = new ChargeDisputeCreatedHandler(disputeCreatedResolver(disputeCreatedInternalId()), $recorder);

    expect($handler(disputeCreatedEvent(), $gatewayId))->toBe(HandlerOutcome::Processed)
        ->and($recorder->observed)->toHaveCount(1)
        ->and($recorder->resolved)->toBe([]);

    [$observedGatewayId, $paymentIntentId, $snapshot] = $recorder->observed[0];

    expect($observedGatewayId->equals($gatewayId))->toBeTrue()
        ->and($paymentIntentId)->toBe(disputeCreatedInternalId())
        // The provider's reference for the case, not ours: it is what ties this call to a later
        // resolution of the same case.
        ->and($snapshot->gatewayDisputeRef)->toBe('dp_case_alpha')
        ->and($snapshot->cardBrand)->toBe(CardBrand::Visa)
        // Raw and untrimmed, both of them. `needs_response` is not `DisputeStatus::NeedsResponse`
        // and the mapping is not this package's to make.
        ->and($snapshot->reasonCode)->toBe('fraudulent')
        ->and($snapshot->statusCode)->toBe('needs_response')
        // Stripe states no stage of its own, so nothing is claimed about one.
        ->and($snapshot->stageCode)->toBeNull()
        ->and($snapshot->disputedAmount?->equals(new Money(1500, new Currency('USD'))))->toBeTrue()
        ->and($snapshot->responseDueAt?->getTimestamp())->toBe(1738195199)
        // The four position signals are ConnexPay's vocabulary and Stripe states none of them.
        ->and($snapshot->outcomeCode)->toBeNull()
        ->and($snapshot->waitingOnCode)->toBeNull()
        ->and($snapshot->hasResponse)->toBeNull()
        ->and($snapshot->familyRef)->toBeNull()
        ->and($snapshot->fees)->toBe([]);
});

it('takes the event id as the provider event key and the event clock as the moment', function () {
    // The whole idempotency contract, in one assertion: the key is the delivery's own identity, so
    // two different deliveries of the same case never share it and a retry of one always does.
    $recorder = disputeCreatedRecorder();
    $handler = new ChargeDisputeCreatedHandler(disputeCreatedResolver('01929fa5-0000-7000-8000-0000000000d1'), $recorder);

    $handler(disputeCreatedEvent(), GatewayId::generate());
    $snapshot = $recorder->observed[0][2];

    expect($snapshot->providerEventKey)->toBe('evt_dispute_created_1')
        // Stripe's `event.created`, not the moment this ran: a delivery read a day late is still
        // filed as having happened when the provider published it.
        ->and($snapshot->observedAt->getTimestamp())->toBe(1737004800);
});

it('returns Skipped when the dispute names no payment intent at all', function () {
    // Absent, not unresolvable: Stripe is telling us this case is not about a payment we track, and
    // there is no later delivery that will change that. Final on purpose.
    $recorder = disputeCreatedRecorder();
    $handler = new ChargeDisputeCreatedHandler(disputeCreatedResolver(), $recorder);

    expect($handler(disputeCreatedEvent(['payment_intent' => null]), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped)
        ->and($recorder->observed)->toBe([]);
});

it('returns Delay when the payment intent cannot be resolved yet', function () {
    // Present but unknown: the reference row tying `pi_…` to our aggregate may be written a moment
    // after the dispute lands. This is arrival order, so it retries rather than being dropped.
    $recorder = disputeCreatedRecorder();
    $handler = new ChargeDisputeCreatedHandler(disputeCreatedResolver(null), $recorder);

    expect($handler(disputeCreatedEvent(), GatewayId::generate()))
        ->toBe(HandlerOutcome::Delay)
        ->and($recorder->observed)->toBe([]);
});

it('reports a case that arrived already lost without rewriting the status', function () {
    // Unchallengeable disputes arrive on THIS event, already decided, and Stripe's API makes them
    // indistinguishable from ordinary ones. The handler must not "fix up" the status into
    // `needs_response` — that would present a case that is over as one waiting on us — and it must
    // not refuse it either. The status is the provider's statement, so it travels verbatim and F1's
    // `open()` creates the aggregate straight into a terminal state.
    $recorder = disputeCreatedRecorder();
    $handler = new ChargeDisputeCreatedHandler(disputeCreatedResolver('01929fa5-0000-7000-8000-0000000000d1'), $recorder);

    expect($handler(disputeCreatedEvent(['status' => 'lost', 'is_charge_refundable' => false]), GatewayId::generate()))
        ->toBe(HandlerOutcome::Processed);

    $snapshot = $recorder->observed[0][2];

    expect($snapshot->statusCode)->toBe('lost')
        ->and($snapshot->stageCode)->toBeNull()
        ->and($snapshot->disputedAmount?->equals(new Money(1500, new Currency('USD'))))->toBeTrue();
});

it('reports a case that arrived already in an inquiry, still stage-less', function () {
    // `warning_needs_response` is a status. It implies an inquiry and says nothing else, and the
    // implication is the mapper's to draw — so the snapshot carries the status and no stage.
    $recorder = disputeCreatedRecorder();
    $handler = new ChargeDisputeCreatedHandler(disputeCreatedResolver('01929fa5-0000-7000-8000-0000000000d1'), $recorder);

    $handler(disputeCreatedEvent([
        'status' => 'warning_needs_response',
        'payment_method_details' => ['card' => ['brand' => 'discover']],
    ]), GatewayId::generate());

    expect($recorder->observed[0][2]->statusCode)->toBe('warning_needs_response')
        ->and($recorder->observed[0][2]->stageCode)->toBeNull()
        ->and($recorder->observed[0][2]->cardBrand)->toBe(CardBrand::Discover);
});

it('maps the recorder outcome it was given, rather than assuming success', function (RecorderOutcome $outcome, HandlerOutcome $expected) {
    $handler = new ChargeDisputeCreatedHandler(
        disputeCreatedResolver('01929fa5-0000-7000-8000-0000000000d1'),
        disputeCreatedRecorder($outcome),
    );

    expect($handler(disputeCreatedEvent(), GatewayId::generate()))->toBe($expected);
})->with([
    'applied' => [RecorderOutcome::Applied, HandlerOutcome::Processed],
    'already known' => [RecorderOutcome::Skipped, HandlerOutcome::Skipped],
    // The recorder could not find the payment's aggregate — a dependency that is not visible yet,
    // which retries exactly like an unresolvable reference does.
    'not visible yet' => [RecorderOutcome::NotFound, HandlerOutcome::Delay],
]);

it('accepts a payment intent that arrived expanded rather than as an id', function () {
    // Webhook payloads carry the id, but a stored payload replayed from an API response may carry
    // the object. Reading that as "absent" would return Skipped — final — and lose a live dispute,
    // which is the one outcome worse than delaying it.
    $recorder = disputeCreatedRecorder();
    $handler = new ChargeDisputeCreatedHandler(disputeCreatedResolver('01929fa5-0000-7000-8000-0000000000d1'), $recorder);

    expect($handler(disputeCreatedEvent([
        'payment_intent' => ['id' => 'pi_shared', 'object' => 'payment_intent'],
    ]), GatewayId::generate()))->toBe(HandlerOutcome::Processed);
});

it('refuses a delivery whose card brand is one CardBrand cannot hold', function () {
    // `cartes_bancaires` is a real network with real chargebacks and no case in the enum. Skipping
    // would be final and silent — a chargeback the ledger never learns about — so the delivery is
    // refused loudly instead, and an operator gets to decide what the network is.
    $handler = new ChargeDisputeCreatedHandler(disputeCreatedResolver('01929fa5-0000-7000-8000-0000000000d1'), disputeCreatedRecorder());

    expect(fn () => $handler(disputeCreatedEvent([
        'payment_method_details' => ['card' => ['brand' => 'cartes_bancaires']],
    ]), GatewayId::generate()))
        ->toThrow(InvalidArgumentException::class, 'cartes_bancaires');
});

it('refuses a delivery that states no card brand at all', function () {
    $handler = new ChargeDisputeCreatedHandler(disputeCreatedResolver('01929fa5-0000-7000-8000-0000000000d1'), disputeCreatedRecorder());

    expect(fn () => $handler(disputeCreatedEvent(['payment_method_details' => null]), GatewayId::generate()))
        ->toThrow(InvalidArgumentException::class, 'no brand at all');
});

it('refuses a dispute with no reference for the case', function () {
    // Nothing else would: the snapshot is the only guard on this path, and a blank reference is
    // not an empty lookup, it is one that matches whichever case was stored first.
    $handler = new ChargeDisputeCreatedHandler(disputeCreatedResolver('01929fa5-0000-7000-8000-0000000000d1'), disputeCreatedRecorder());

    expect(fn () => $handler(disputeCreatedEvent(['id' => '']), GatewayId::generate()))
        ->toThrow(InvalidArgumentException::class);
});
