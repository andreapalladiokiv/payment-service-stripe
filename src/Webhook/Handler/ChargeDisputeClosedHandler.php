<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook\Handler;

use InvalidArgumentException;
use Override;
use Stripe\Event;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Stripe\Webhook\DisputePayload;

/**
 * Stripe `charge.dispute.closed` — the case is over, one way or another.
 *
 * The event is named for the case ending, not for the case being decided, and the two are not the
 * same: it fires for a `won` and a `lost`, and it also fires for `warning_closed`, which is an
 * inquiry that sat out its 120 days without escalating and that nobody ever ruled on. Treating the
 * event name as the fact is what would file that third one as an outcome.
 *
 * ## Two calls, and the branch is which key the delivery can be addressed by
 *
 * **`won` / `lost` go out through `onDisputeResolved()`, keyed on the provider's reference.** This
 * is the one of the three handlers that can be reached with no usable payment reference at all —
 * a case whose PaymentIntent we never resolved, a backlog import, a resolution that arrives for a
 * case we only ever saw after the fact — and the recorder's resolution call exists for exactly
 * that: it is addressed by the reference Stripe states on the case, so it does not need the
 * payment and does not need the resolver. Deliberately *not* the observed call: that one requires
 * a payment, and a resolution that cannot name one would then be dropped for the worst possible
 * reason. The consequence is accepted knowingly — the recorder answers `NotFound` when no case
 * exists yet, and that becomes `Delay`, because a resolution can only ever land on a case that was
 * opened first. `charge.dispute.created` precedes this event in every ordinary delivery; a retry
 * that reorders them is settled by waiting, which is what `Delay` means.
 *
 * **Everything else goes out through `onDisputeObserved()`**, because it is not an outcome: a case
 * closed for a reason that decides nothing gets its status recorded against the payment like any
 * other statement about the case. That path keeps the same two answers its siblings use for the
 * payment reference — absent means `Skipped`, unresolvable means `Delay`, see
 * {@see ChargeUpdatedHandler} — and the same verbatim status, so a `warning_closed` is filed as
 * `warning_closed` and the mapper decides what stage and status that is.
 *
 * ## An unrecognised status is treated as a statement, never as an outcome
 *
 * {@see DisputePayload::isOutcome()} answers for `won` and `lost` and nothing else, so a status
 * Stripe adds later falls to the observed path rather than to the resolution one. The failure
 * directions are not symmetric: a new status filed as an observation is a case with a status the
 * mapper has to look at, while a new status filed as a resolution writes an outcome — money — that
 * the networks never decided.
 *
 * @implements WebhookEventHandler<Event>
 */
final readonly class ChargeDisputeClosedHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewayDisputeRecorder $recorder,
    ) {}

    #[Override]
    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var Event $event */
        $dispute = DisputePayload::from($event);

        if (! $dispute->isOutcome()) {
            return $this->reportObserved($dispute, $gatewayId);
        }

        $reference = $dispute->reference();

        // The resolution call names the case in its own signature, with no DTO between the two, so
        // nothing else would refuse a blank one on the way through — and a blank key is not an
        // empty lookup here, it is a lookup that matches whichever case the store returns first.
        // The snapshot refuses it on the other path for the same reason; this is that refusal,
        // where the resolution path has no snapshot to make it.
        $reference !== '' || throw new InvalidArgumentException(
            'A Stripe dispute closed as an outcome carries no reference for the case, so there is '
            . 'nothing to record the resolution against. The reference is the key this call is '
            . 'addressed by, and a blank one would resolve to whichever case was stored first.',
        );

        return match ($this->recorder->onDisputeResolved($gatewayId, $reference, $dispute->resolution())) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }

    /**
     * The closing of a case that decides nothing, reported as a statement about the payment.
     *
     * Identical to what the other two handlers do with a delivery — which is the point: a case does
     * not stop being an observation because the event that carries it is called `closed`.
     */
    private function reportObserved(DisputePayload $dispute, GatewayId $gatewayId): HandlerOutcome
    {
        $paymentIntentReference = $dispute->paymentIntentReference();
        if ($paymentIntentReference === '') {
            return HandlerOutcome::Skipped;
        }

        $paymentIntentId = $this->resolver->resolvePaymentIntent($gatewayId, $paymentIntentReference);
        if ($paymentIntentId === null) {
            return HandlerOutcome::Delay;
        }

        return match ($this->recorder->onDisputeObserved($gatewayId, $paymentIntentId, $dispute->snapshot())) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }
}
