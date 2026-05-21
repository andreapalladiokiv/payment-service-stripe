<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook;

use Techork\PaymentService\Gateway\Webhook\Contract\EventParser as EventParserContract;
use Techork\PaymentService\Gateway\Webhook\Contract\ParsedEvent;
use Stripe\Event;

/**
 * Rebuilds a {@see Event} from the stored payload and exposes the Stripe
 * event id as the idempotency key.
 */
final readonly class EventParser implements EventParserContract
{
    /**
     * @return ParsedEvent<Event>
     */
    public function parse(array $payload): ParsedEvent
    {
        $event = Event::constructFrom($payload);

        return new ParsedEvent($event->type ?? '', $event->id ?? '', $event);
    }
}
