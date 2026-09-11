<?php

declare(strict_types=1);

use Money\Money;
use Stripe\Util\Util;

use Techork\PaymentService\Stripe\Webhook\Handler\ChargeRefundedHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundProcessingRecorder;

function chargeRefundedEvent(string $piReference = 'ch_123', string $refundReference = 're_abc', int $amount = 1000, string $currency = 'usd'): object
{
    return Util::convertToStripeObject([
        'id' => 'evt_1',
        'type' => 'charge.refunded',
        'data' => ['object' => [
            'id' => $piReference,
            'object' => 'charge',
            'payment_intent' => $piReference,
            'refunds' => [
                'object' => 'list',
                'data' => [[
                    'id' => $refundReference,
                    'object' => 'refund',
                    'amount' => $amount,
                    'currency' => $currency,
                ]],
            ],
        ]],
    ], []);
}

it('refuses to book a refund that names no currency instead of assuming USD', function () {
    $gatewayId = GatewayId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn('01942f6e-1c3a-7b8d-9e4f-'.uniqid());

    $recorder = Mockery::mock(RefundProcessingRecorder::class);
    $recorder->shouldNotReceive('onRefundProcessed');

    $handler = new ChargeRefundedHandler($resolver, $recorder);

    expect(fn () => $handler(chargeRefundedEvent(currency: ''), $gatewayId))
        ->toThrow(RuntimeException::class, 'names no currency');
});

it('delegates to RefundProcessingRecorder with the resolved PaymentIntent id', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . uniqid();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->with($gatewayId, 'ch_123')->andReturn($piId);

    $recorder = Mockery::mock(RefundProcessingRecorder::class);
    $recorder->shouldReceive('onRefundProcessed')
        ->once()
        ->with($gatewayId, $piId, 're_abc', Mockery::on(fn (Money $m) => $m->getAmount() === '1000' && $m->getCurrency()->getCode() === 'USD'))
        ->andReturn(RecorderOutcome::Applied);

    expect(new ChargeRefundedHandler($resolver, $recorder)(chargeRefundedEvent(), $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('returns Skipped when no refunds are attached to the charge', function () {
    $event = Util::convertToStripeObject([
        'id' => 'evt_1',
        'type' => 'charge.refunded',
        'data' => ['object' => [
            'id' => 'ch_123',
            'object' => 'charge',
            'payment_intent' => 'ch_123',
            'refunds' => ['object' => 'list', 'data' => []],
        ]],
    ], []);

    $handler = new ChargeRefundedHandler(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(RefundProcessingRecorder::class),
    );

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});

/**
 * Stripe's embedded refunds list is newest-first, and charge.refunded fires once per refund —
 * so the one that fired is data[0]. This is the case where getting it wrong is invisible: the
 * recorder deduplicates by reference, so re-sending the oldest refund over and over just
 * drops the event while the refund that actually fired is never booked.
 */
it('books the refund that fired, not the oldest one on the charge', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-'.uniqid();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn($piId);

    $recorder = Mockery::mock(RefundProcessingRecorder::class);
    $recorder->shouldReceive('onRefundProcessed')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $pi, string $reference) => $reference === 're_new')
        ->andReturn(RecorderOutcome::Applied);

    $event = chargeRefundedEvent(refundReference: 're_oldest');
    $newest = Util::convertToStripeObject(['id' => 're_new', 'object' => 'refund', 'amount' => 500, 'currency' => 'usd'], []);
    $refunds = $event->data->object->refunds;
    array_unshift($refunds->data, $newest);

    expect(new ChargeRefundedHandler($resolver, $recorder)($event, $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('returns Delay when the PaymentIntent reference is unknown', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturnNull();

    $recorder = Mockery::mock(RefundProcessingRecorder::class);
    $recorder->shouldNotReceive('onRefundProcessed');

    expect(new ChargeRefundedHandler($resolver, $recorder)(chargeRefundedEvent(), GatewayId::generate()))
        ->toBe(HandlerOutcome::Delay);
});
