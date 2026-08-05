<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook\Handler;

use Override;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Money\Currency;
use Money\Money;
use RuntimeException;
use Stripe\Event;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * @implements WebhookEventHandler<Event>
 */
final readonly class PaymentIntentSucceededHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewaySuccessRecorder $recorder,
    ) {}

    #[Override]
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

        $currency = strtoupper((string) ($object->currency ?? ''));
        if ($currency === '') {
            throw new RuntimeException(
                sprintf('Stripe payment_intent.succeeded %s names no currency; refusing to assume one.', $reference),
            );
        }

        $amount = new Money(
            (int) ($object->amount_received ?? $object->amount ?? 0),
            new Currency($currency),
        );

        return match ($this->recorder->onGatewaySuccess($gatewayId, $paymentIntentId, $reference, $amount)) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }
}
