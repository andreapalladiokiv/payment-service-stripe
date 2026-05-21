<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook\Handler;

use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundProcessingRecorder;
use Money\Currency;
use Money\Money;
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

        $amount = new Money(
            (int) ($refund->amount ?? 0),
            new Currency(strtoupper((string) ($refund->currency ?? 'USD'))),
        );

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

        $latest = end($refunds);

        return is_object($latest) ? $latest : null;
    }
}
