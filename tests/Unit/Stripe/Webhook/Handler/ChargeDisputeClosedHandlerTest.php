<?php

declare(strict_types=1);

use Stripe\Util\Util;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeResolution;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\UnmatchedDispute;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeDisputeClosedHandler;

/**
 * `charge.dispute.closed` — the case is over. Which is not the same as the case being decided.
 *
 * Two things are pinned here that the other two handlers cannot show: that an outcome reaches the
 * recorder through the call keyed on the *case* and needs no payment lookup at all, and that a case
 * closed without a decision does not go through that call.
 *
 * Pest helpers are global, so every function in this file is named for this file.
 */
function disputeClosedEvent(array $dispute = []): object
{
    return Util::convertToStripeObject([
        'id' => 'evt_dispute_closed_1',
        'object' => 'event',
        'type' => 'charge.dispute.closed',
        'created' => 1738100000,
        'data' => ['object' => array_replace([
            'id' => 'dp_case_alpha',
            'object' => 'dispute',
            'amount' => 1500,
            'currency' => 'usd',
            'payment_intent' => 'pi_shared',
            'reason' => 'fraudulent',
            'status' => 'won',
            'payment_method_details' => ['card' => ['brand' => 'visa']],
            'evidence_details' => ['due_by' => 1738195199],
        ], $dispute)],
    ], []);
}

function disputeClosedRecorder(RecorderOutcome $outcome = RecorderOutcome::Applied): GatewayDisputeRecorder
{
    return new class($outcome) implements GatewayDisputeRecorder
    {
        /** @var list<DisputeSnapshot> */
        public array $observed = [];

        /** @var list<array{0: string, 1: DisputeResolution}> */
        public array $resolved = [];

        public function __construct(private RecorderOutcome $outcome) {}

        public function onDisputeObserved(GatewayId $gatewayId, string $paymentIntentId, DisputeSnapshot $snapshot): RecorderOutcome
        {
            $this->observed[] = $snapshot;

            return $this->outcome;
        }

        public function onDisputeResolved(GatewayId $gatewayId, string $disputeRef, DisputeResolution $resolution): RecorderOutcome
        {
            $this->resolved[] = [$disputeRef, $resolution];

            return $this->outcome;
        }

        public function onUnmatchedDispute(GatewayId $gatewayId, UnmatchedDispute $case): RecorderOutcome
        {
            return $this->outcome;
        }
    };
}

/**
 * A resolver that fails the test if it is used at all.
 *
 * The resolution path must not need it: `onDisputeResolved()` is addressed by the provider's
 * reference for the case, and the whole reason that call is keyed that way is that a resolution can
 * reach us for a case whose payment never resolved. A lookup here would turn that into a `Delay`
 * that never ends, so its absence is asserted rather than assumed.
 */
function disputeClosedUnusedResolver(): TransactionIdResolver
{
    return new class implements TransactionIdResolver
    {
        public function resolvePaymentIntent(GatewayId $gatewayId, string $reference): ?string
        {
            throw new RuntimeException('the resolution path must not resolve a payment intent');
        }

        public function resolveRefund(GatewayId $gatewayId, string $reference): ?string
        {
            throw new RuntimeException('the resolution path must not resolve a refund');
        }
    };
}

function disputeClosedResolver(?string $internalId): TransactionIdResolver
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

it('reports a won case through the resolution call, addressed by the case and keyed by the event', function () {
    $recorder = disputeClosedRecorder();
    $handler = new ChargeDisputeClosedHandler(disputeClosedUnusedResolver(), $recorder);

    expect($handler(disputeClosedEvent(), GatewayId::generate()))->toBe(HandlerOutcome::Processed)
        ->and($recorder->observed)->toBe([])
        ->and($recorder->resolved)->toHaveCount(1);

    [$reference, $resolution] = $recorder->resolved[0];

    // Stripe's reference for the case, not ours and not the payment's: this call is addressed by it
    // because a resolution can arrive for a case we never tied to a payment.
    expect($reference)->toBe('dp_case_alpha')
        ->and($resolution->statusCode)->toBe('won')
        ->and($resolution->providerEventKey)->toBe('evt_dispute_closed_1')
        ->and($resolution->observedAt->getTimestamp())->toBe(1738100000)
        ->and($resolution->familyRef)->toBeNull();
});

it('reports a lost case with no payment intent on it at all', function () {
    // The case this design exists for: nothing to resolve, nothing to delay, and an outcome that
    // would otherwise be dropped for the worst possible reason.
    $recorder = disputeClosedRecorder();
    $handler = new ChargeDisputeClosedHandler(disputeClosedUnusedResolver(), $recorder);

    expect($handler(disputeClosedEvent(['status' => 'lost', 'payment_intent' => null]), GatewayId::generate()))
        ->toBe(HandlerOutcome::Processed)
        ->and($recorder->resolved[0][1]->statusCode)->toBe('lost');
});

it('reports an inquiry that closed without a decision as an observation, not as an outcome', function () {
    // `warning_closed` is the 120 days running out on an inquiry nobody escalated. The event is
    // called `closed` and the case is closed, but no network decided anything, so filing it as a
    // resolution would write money that never moved.
    $recorder = disputeClosedRecorder();
    $handler = new ChargeDisputeClosedHandler(disputeClosedResolver('01929fa5-0000-7000-8000-0000000000d3'), $recorder);

    expect($handler(disputeClosedEvent(['status' => 'warning_closed']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Processed)
        ->and($recorder->resolved)->toBe([])
        ->and($recorder->observed)->toHaveCount(1);

    expect($recorder->observed[0]->statusCode)->toBe('warning_closed')
        ->and($recorder->observed[0]->stageCode)->toBeNull();
});

it('treats any status that is not an outcome as a statement, never as a resolution', function () {
    // The failure directions are not symmetric: a status filed as an observation is a case the
    // mapper has to look at, while a status filed as a resolution writes an outcome the networks
    // never decided. So the resolution call is entered only on `won` and `lost`.
    $recorder = disputeClosedRecorder();
    $handler = new ChargeDisputeClosedHandler(disputeClosedResolver('01929fa5-0000-7000-8000-0000000000d3'), $recorder);

    $handler(disputeClosedEvent(['status' => 'under_review']), GatewayId::generate());

    expect($recorder->resolved)->toBe([])
        ->and($recorder->observed[0]->statusCode)->toBe('under_review');
});

it('keeps the Skipped/Delay line on the closing of a case that decides nothing', function () {
    $handler = new ChargeDisputeClosedHandler(disputeClosedResolver(null), disputeClosedRecorder());

    expect($handler(disputeClosedEvent(['status' => 'warning_closed']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Delay);

    expect($handler(disputeClosedEvent(['status' => 'warning_closed', 'payment_intent' => null]), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('refuses a resolution with no reference for the case', function () {
    // The resolution call names the case in its own signature with no DTO in between, so this is
    // the only guard there is — and a blank reference is not an empty lookup, it is a lookup that
    // matches whichever case was stored first.
    $handler = new ChargeDisputeClosedHandler(disputeClosedUnusedResolver(), disputeClosedRecorder());

    expect(fn () => $handler(disputeClosedEvent(['id' => '']), GatewayId::generate()))
        ->toThrow(InvalidArgumentException::class);
});

it('maps the recorder outcome it was given on the resolution call', function (RecorderOutcome $outcome, HandlerOutcome $expected) {
    $handler = new ChargeDisputeClosedHandler(disputeClosedUnusedResolver(), disputeClosedRecorder($outcome));

    expect($handler(disputeClosedEvent(), GatewayId::generate()))->toBe($expected);
})->with([
    'applied' => [RecorderOutcome::Applied, HandlerOutcome::Processed],
    'already known' => [RecorderOutcome::Skipped, HandlerOutcome::Skipped],
    // The case has not been observed yet — the created event is presumably still in flight, and
    // waiting is the whole of the answer.
    'not visible yet' => [RecorderOutcome::NotFound, HandlerOutcome::Delay],
]);
