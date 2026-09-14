<?php

declare(strict_types=1);

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
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeDisputeUpdatedHandler;

/**
 * `charge.dispute.updated` — the same dispute object, re-sent whenever anything about it moves.
 *
 * The handler is the same one `charge.dispute.created` gets, so what is worth pinning here is what
 * makes this event different rather than what makes it the same: that a second genuine update is
 * never mistaken for a repeat of the first, and that a repeat of one is recognised.
 *
 * Pest helpers are global, so every function in this file is named for this file.
 */
function disputeUpdatedEvent(array $dispute = [], string $eventId = 'evt_dispute_updated_1'): object
{
    return Util::convertToStripeObject([
        'id' => $eventId,
        'object' => 'event',
        'type' => 'charge.dispute.updated',
        'created' => 1737300000,
        'data' => ['object' => array_replace([
            'id' => 'dp_case_delta',
            'object' => 'dispute',
            'amount' => 4200,
            'currency' => 'usd',
            'payment_intent' => 'pi_inquiry',
            'reason' => 'product_not_received',
            'status' => 'warning_needs_response',
            'payment_method_details' => ['card' => ['brand' => 'discover']],
            'evidence_details' => ['due_by' => 1738583999],
        ], $dispute)],
    ], []);
}

function disputeUpdatedRecorder(RecorderOutcome $outcome = RecorderOutcome::Applied): GatewayDisputeRecorder
{
    return new class($outcome) implements GatewayDisputeRecorder
    {
        /** @var list<DisputeSnapshot> */
        public array $observed = [];

        /** @var list<DisputeResolution> */
        public array $resolved = [];

        public function __construct(private RecorderOutcome $outcome) {}

        public function onDisputeObserved(GatewayId $gatewayId, string $paymentIntentId, DisputeSnapshot $snapshot): RecorderOutcome
        {
            $this->observed[] = $snapshot;

            return $this->outcome;
        }

        public function onDisputeResolved(GatewayId $gatewayId, string $disputeRef, DisputeResolution $resolution): RecorderOutcome
        {
            $this->resolved[] = $resolution;

            return $this->outcome;
        }

        public function onUnmatchedDispute(GatewayId $gatewayId, UnmatchedDispute $case): RecorderOutcome
        {
            return $this->outcome;
        }
    };
}

function disputeUpdatedResolver(?string $internalId = '01929fa5-0000-7000-8000-0000000000d2'): TransactionIdResolver
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

it('reports an inquiry update as a statement about the payment, with no stage claimed', function () {
    $recorder = disputeUpdatedRecorder();
    $handler = new ChargeDisputeUpdatedHandler(disputeUpdatedResolver(), $recorder);

    expect($handler(disputeUpdatedEvent(), GatewayId::generate()))->toBe(HandlerOutcome::Processed)
        ->and($recorder->observed)->toHaveCount(1);

    $snapshot = $recorder->observed[0];

    // `warning_needs_response` is Stripe's status, verbatim. That it means an inquiry is the
    // mapper's reading, on the other side of this boundary.
    expect($snapshot->statusCode)->toBe('warning_needs_response')
        ->and($snapshot->stageCode)->toBeNull()
        ->and($snapshot->cardBrand)->toBe(CardBrand::Discover)
        ->and($snapshot->reasonCode)->toBe('product_not_received')
        ->and($snapshot->gatewayDisputeRef)->toBe('dp_case_delta');
});

it('gives a second update of one case its own event key, and a retry of one its own key back', function () {
    // The idempotency contract, stated as the two facts it is made of. A case that moved twice must
    // be reported twice — Stripe re-sends the whole object, and the two payloads can be identical
    // where the change is in a field this DTO does not carry, so the *event* is what tells them
    // apart. A redelivery of one of those events must not be reported again.
    $recorder = disputeUpdatedRecorder();
    $handler = new ChargeDisputeUpdatedHandler(disputeUpdatedResolver(), $recorder);
    $gatewayId = GatewayId::generate();

    $handler(disputeUpdatedEvent(['status' => 'under_review'], 'evt_dispute_updated_1'), $gatewayId);
    $handler(disputeUpdatedEvent(['status' => 'under_review'], 'evt_dispute_updated_2'), $gatewayId);
    // The first event again, byte for byte, as Stripe's retry sends it.
    $handler(disputeUpdatedEvent(['status' => 'under_review'], 'evt_dispute_updated_1'), $gatewayId);

    $keys = array_map(static fn (DisputeSnapshot $s): string => $s->providerEventKey, $recorder->observed);

    expect($keys)->toBe(['evt_dispute_updated_1', 'evt_dispute_updated_2', 'evt_dispute_updated_1'])
        ->and(array_unique($keys))->toHaveCount(2);
});

it('reports an update that arrived already lost without rewriting the status', function () {
    // An update can carry a terminal status — a case we never saw the opening of, or a late win.
    // It goes out as an observation of the case as Stripe states it, which is the same shape the
    // created handler uses and the reason `DisputeAggregate::open()` takes a status at all.
    $recorder = disputeUpdatedRecorder();
    $handler = new ChargeDisputeUpdatedHandler(disputeUpdatedResolver(), $recorder);

    expect($handler(disputeUpdatedEvent(['status' => 'lost']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Processed)
        ->and($recorder->observed[0]->statusCode)->toBe('lost')
        ->and($recorder->resolved)->toBe([]);
});

it('returns Skipped when the update names no payment intent', function () {
    $recorder = disputeUpdatedRecorder();
    $handler = new ChargeDisputeUpdatedHandler(disputeUpdatedResolver(), $recorder);

    expect($handler(disputeUpdatedEvent(['payment_intent' => null]), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped)
        ->and($recorder->observed)->toBe([]);
});

it('returns Delay when the payment intent cannot be resolved yet', function () {
    $recorder = disputeUpdatedRecorder();
    $handler = new ChargeDisputeUpdatedHandler(disputeUpdatedResolver(null), $recorder);

    expect($handler(disputeUpdatedEvent(), GatewayId::generate()))
        ->toBe(HandlerOutcome::Delay)
        ->and($recorder->observed)->toBe([]);
});

it('maps the recorder outcome it was given', function (RecorderOutcome $outcome, HandlerOutcome $expected) {
    $handler = new ChargeDisputeUpdatedHandler(disputeUpdatedResolver(), disputeUpdatedRecorder($outcome));

    expect($handler(disputeUpdatedEvent(), GatewayId::generate()))->toBe($expected);
})->with([
    'applied' => [RecorderOutcome::Applied, HandlerOutcome::Processed],
    'already known' => [RecorderOutcome::Skipped, HandlerOutcome::Skipped],
    'not visible yet' => [RecorderOutcome::NotFound, HandlerOutcome::Delay],
]);
