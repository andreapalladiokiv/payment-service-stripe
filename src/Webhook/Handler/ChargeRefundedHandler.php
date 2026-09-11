<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook\Handler;

use Override;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundProcessingRecorder;
use Money\Currency;
use Money\Money;
use RuntimeException;
use Stripe\Event;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Stripe `charge.refunded` — confirms a refund has been issued. We may have
 * initiated it via our API (aggregate already exists) or it may have been
 * issued from the Stripe dashboard (recorder creates the aggregate).
 *
 * @implements WebhookEventHandler<Event>
 */
final readonly class ChargeRefundedHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private RefundProcessingRecorder $recorder,
    ) {}

    #[Override]
    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var Event $event */
        $refund = $this->extractLatestRefund($event);
        if ($refund === null) {
            return HandlerOutcome::Skipped;
        }

        $paymentIntentReference = $event->data->object->payment_intent ?? null;
        if (! is_string($paymentIntentReference) || $paymentIntentReference === '') {
            return HandlerOutcome::Skipped;
        }

        $refundReference = (string) ($refund->id ?? '');
        if ($refundReference === '') {
            return HandlerOutcome::Skipped;
        }

        $paymentIntentId = $this->resolver->resolvePaymentIntent($gatewayId, $paymentIntentReference);
        if ($paymentIntentId === null) {
            return HandlerOutcome::Delay;
        }

        $currency = strtoupper((string) ($refund->currency ?? ''));
        if ($currency === '') {
            throw new RuntimeException(
                sprintf('Stripe refund %s names no currency; refusing to assume one.', $refundReference),
            );
        }

        $amount = new Money((int) ($refund->amount ?? 0), new Currency($currency));

        return match ($this->recorder->onRefundProcessed($gatewayId, $paymentIntentId, $refundReference, $amount)) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }

    private function extractLatestRefund(object $event): ?object
    {
        $refunds = $event->data->object->refunds->data ?? null;
        if (! is_array($refunds) || count($refunds) === 0) {
            return null;
        }

        // Stripe's refunds list is newest-first (docs.stripe.com/api/refunds/list: "most recent
        // refunds appearing first"), and charge.refunded fires once per refund created — so the
        // one that fired is data[0]. `end()` here booked the OLDEST refund of the charge
        // instead: with two or more partial refunds every event re-sent the first refund's
        // reference and amount, which the recorder deduplicated away, and the refund that
        // actually fired was never recorded at all.
        $latest = reset($refunds);

        return is_object($latest) ? $latest : null;
    }
}
