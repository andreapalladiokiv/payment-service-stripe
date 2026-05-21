<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook\Handler;

use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Stripe\Event;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * @implements WebhookEventHandler<Event>
 */
final readonly class PaymentIntentFailedHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewayFailureRecorder $recorder,
    ) {}

    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var Event $event */
        $object = $event->data->object;
        $reference = (string) ($object->id ?? '');
        if ($reference === '') {
            return HandlerOutcome::Skipped;
        }

        $paymentIntentId = $this->resolver->resolvePaymentIntent($gatewayId, $reference);
        if ($paymentIntentId === null) {
            return HandlerOutcome::Delay;
        }

        $reason = (string) ($object->last_payment_error->message
            ?? $object->last_payment_error->code
            ?? 'Payment failed at gateway');

        return match ($this->recorder->onGatewayFailure($paymentIntentId, $reason)) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }
}
