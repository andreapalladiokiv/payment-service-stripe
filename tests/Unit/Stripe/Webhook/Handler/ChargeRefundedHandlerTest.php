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

function chargeRefundedEvent(string $piReference = 'ch_123', string $refundReference = 're_abc', int $amount = 1000): object
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
                    'currency' => 'usd',
                ]],
            ],
        ]],
    ], []);
}

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

    expect((new ChargeRefundedHandler($resolver, $recorder))(chargeRefundedEvent(), $gatewayId))
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

it('returns Delay when the PaymentIntent reference is unknown', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturnNull();

    $recorder = Mockery::mock(RefundProcessingRecorder::class);
    $recorder->shouldNotReceive('onRefundProcessed');

    expect((new ChargeRefundedHandler($resolver, $recorder))(chargeRefundedEvent(), GatewayId::generate()))
        ->toBe(HandlerOutcome::Delay);
});
