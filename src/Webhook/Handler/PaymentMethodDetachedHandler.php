<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook\Handler;

use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\InstrumentReferenceEraser;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Stripe\Event;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Stripe `payment_method.detached`: the card was detached from its customer at
 * Stripe. We forget the gateway-side reference for this payment method on the
 * source gateway; the local PaymentMethod and related data are preserved.
 *
 * @implements WebhookEventHandler<Event>
 */
final readonly class PaymentMethodDetachedHandler implements WebhookEventHandler
{
    public function __construct(
        private InstrumentReferenceEraser $eraser,
    ) {}

    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var Event $event */
        $paymentMethodReference = (string) ($event->data->object->id ?? '');
        if ($paymentMethodReference === '') {
            return HandlerOutcome::Skipped;
        }

        return $this->eraser->forgetPaymentMethodReference($gatewayId, $paymentMethodReference)
            ? HandlerOutcome::Processed
            : HandlerOutcome::Skipped;
    }
}
