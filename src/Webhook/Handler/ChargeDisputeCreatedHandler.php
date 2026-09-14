<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook\Handler;

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
 * Stripe `charge.dispute.created` — a case has been raised against one of our payments.
 *
 * ## The two ways a payment reference can fail are not the same outcome
 *
 * {@see ChargeUpdatedHandler} draws this line and this handler keeps it, because the difference is
 * between a delivery we are finished with and one we are not:
 *
 *  - **`payment_intent` absent from the payload → `Skipped`.** Stripe names no payment, so the
 *    event is not about one of ours — a case on another account, a legacy charge raised outside the
 *    PaymentIntent API, a dispute on a payment method we never processed. `Skipped` is final:
 *    nothing retries, because nothing is going to change.
 *  - **present but not found through `GatewayReference` → `Delay`.** This is arrival order. The
 *    dispute can reach us before the reference row that ties `pi_…` to our aggregate is written,
 *    and that row will exist a moment later. Retrying is the whole of the answer.
 *
 * Neither throws. A handler that threw on the first would turn a routine out-of-scope delivery
 * into an alert, and one that threw on the second would turn a race into a lost chargeback.
 *
 * ## A case can arrive already decided, and this handler must not "fix" that
 *
 * Unchallengeable disputes — the ones the networks decide before the merchant is ever asked, and
 * which Stripe's API makes indistinguishable from ordinary ones — arrive on *this* event with
 * `status: "lost"`. There is no earlier `created` for us to have missed and no later one coming.
 *
 * So the snapshot is the payload verbatim, and the status rides inside it as Stripe's own code:
 * `lost`. Nothing here rewrites it to `needs_response` so that the case "opens properly", and
 * nothing here refuses it as a state that could not have been reached. F1's
 * `DisputeAggregate::open()` takes the opening status as an argument precisely so that a case first
 * observed part-way through its life — a backlog import, or this — can be created directly in a
 * terminal state; the recording side opens such a snapshot as it stands. A handler that normalised
 * the status would make every unchallengeable dispute look like one still waiting for an answer,
 * and an operator would spend the window on a case that was already over.
 *
 * ## Why this is the observed call and not the resolution one
 *
 * A `lost` on this event is the *same* status the closed event carries, but it is not the same
 * fact: this is the first delivery of the case, and the case it describes does not exist on our
 * side yet. `onDisputeObserved()` is keyed on the payment and is the call that can open one;
 * `onDisputeResolved()` is keyed on the provider's reference and addresses a case that already
 * exists. Reporting a first sighting through the resolution call would leave it addressing nothing
 * — which is exactly what {@see ChargeDisputeClosedHandler} relies on not having happened.
 *
 * @implements WebhookEventHandler<Event>
 */
final readonly class ChargeDisputeCreatedHandler implements WebhookEventHandler
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
