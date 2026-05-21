<?php

declare(strict_types=1);

use Stripe\Event;
use Techork\PaymentService\Stripe\Webhook\EventParser;

it('parses a Stripe event payload into a ParsedEvent', function () {
    $parsed = (new EventParser)->parse([
        'id' => 'evt_123',
        'object' => 'event',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_123', 'object' => 'payment_intent']],
    ]);

    expect($parsed->type)->toBe('payment_intent.succeeded')
        ->and($parsed->externalId)->toBe('evt_123')
        ->and($parsed->native)->toBeInstanceOf(Event::class);
});

it('returns empty externalId when the payload lacks id', function () {
    $parsed = (new EventParser)->parse([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => []],
    ]);

    expect($parsed->externalId)->toBe('')
        ->and($parsed->type)->toBe('payment_intent.succeeded');
});
