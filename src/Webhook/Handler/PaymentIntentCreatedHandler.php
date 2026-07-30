<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook\Handler;

use Money\Currency;
use Money\Money;
use RuntimeException;
use Stripe\Event;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentIntentRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

/**
 * The genesis event: `payment_intent.created` is the first thing Stripe says
 * about an intent, and the only handler here allowed to create one locally.
 *
 * Every other PaymentIntent handler resolves an existing local id and delays
 * when it finds none, which is right for events that overtook their
 * predecessors — `charge.updated` beating `payment_intent.succeeded` converges
 * once the earlier one lands. It converges on nothing when the intent itself was
 * never recorded this side, and those retries run out and fail. This handler is
 * what they converge on.
 *
 * @implements WebhookEventHandler<Event>
 */
final readonly class PaymentIntentCreatedHandler implements WebhookEventHandler
{
    public function __construct(
        private GatewayPaymentIntentRecorder $recorder,
    ) {}

    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var Event $event */
        $object = $event->data->object;
        $reference = (string) ($object->id ?? '');
        if ($reference === '') {
            return HandlerOutcome::Skipped;
        }

        $currency = strtoupper((string) ($object->currency ?? ''));
        if ($currency === '') {
            throw new RuntimeException(
                sprintf('Stripe payment_intent.created %s names no currency; refusing to assume one.', $reference),
            );
        }

        // `amount`, not `amount_received`: nothing has been received. An intent
        // is created with the amount it intends to take, and that is the figure
        // the local aggregate is opened with.
        $amount = new Money((int) ($object->amount ?? 0), new Currency($currency));

        return match ($this->recorder->onPaymentIntentRecord(
            gatewayId: $gatewayId,
            paymentIntentReference: $reference,
            amount: $amount,
            paymentMethodReference: self::paymentMethodReference($object),
            description: self::text($object->description ?? null),
            merchantDescriptor: self::text($object->statement_descriptor ?? null),
        )) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            // Already recorded — either this event is a redelivery, or the
            // application created the intent itself and Stripe is telling us
            // what we already know. Both are the same nothing-to-do.
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            // Left available on purpose: an application may want to hold the
            // intent back until something it references is known locally.
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }

    /**
     * Stripe sends `payment_method` as a bare id, or as the expanded object when
     * the webhook endpoint asks for it. Reading only the string form would drop
     * the reference on any account configured to expand.
     */
    private static function paymentMethodReference(object $object): ?string
    {
        $paymentMethod = $object->payment_method ?? null;

        $reference = match (true) {
            is_string($paymentMethod) => $paymentMethod,
            is_object($paymentMethod) => (string) ($paymentMethod->id ?? ''),
            default => '',
        };

        return $reference === '' ? null : $reference;
    }

    /**
     * Absent and empty mean the same thing here — the gateway told us nothing —
     * and null says that where `''` would look like a deliberate blanking.
     */
    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
