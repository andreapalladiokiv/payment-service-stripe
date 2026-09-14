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
 * Stripe `charge.dispute.updated` — the case moved, or something about it changed.
 *
 * This is the noisiest of the three and the one that carries the most of the case's life: the
 * evidence window moving, evidence being submitted, the status becoming `under_review`, an inquiry
 * (`warning_needs_response`) being raised. Stripe re-sends the *whole* dispute object every time,
 * so each delivery is a complete statement of where the case stands rather than a delta — which is
 * why the snapshot is built here exactly as it is on `created` and why nothing needs to be merged
 * with what a previous delivery said. The aggregate is the thing that holds the previous state and
 * decides whether this statement says anything new.
 *
 * ## A repeat here is caught by the event, not by a guess about the payload
 *
 * Two deliveries of this event can carry byte-identical dispute objects — Stripe fires it for
 * changes this service does not model, and a re-poll of an unchanged case looks the same as the
 * first look. They are still two deliveries, and they are told apart by `event.id`: the same
 * retried event carries the same id and is dropped, while a second genuine event carries a new one
 * and is applied. Comparing payloads instead would be the trap — the first evidence submission and
 * the second often differ by nothing this DTO carries, and suppressing the second as a duplicate
 * would silently drop a case's only statement of intent.
 *
 * ## The traps this shares with `created`
 *
 * A missing `payment_intent` is `Skipped` and an unresolvable one is `Delay`, exactly as there and
 * as {@see ChargeUpdatedHandler} draws it — the same arrival-order race, the same final answer for
 * a delivery that is not about a payment we track.
 *
 * A status that arrives terminal on this event — `lost` on a case whose opening we somehow missed,
 * or a late `won` — is carried through verbatim, for the reason set out on
 * {@see ChargeDisputeCreatedHandler}: the status is the provider's statement, and refusing to
 * record one we did not expect would lose the outcome rather than file it.
 *
 * @implements WebhookEventHandler<Event>
 */
final readonly class ChargeDisputeUpdatedHandler implements WebhookEventHandler
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
