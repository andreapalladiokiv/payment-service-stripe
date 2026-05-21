<?php

declare(strict_types=1);

use Stripe\Util\Util;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentMethodDetachedHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\InstrumentReferenceEraser;

function pmDetachedEvent(string $id = 'pm_abc'): object
{
    return Util::convertToStripeObject([
        'id' => 'evt_1',
        'type' => 'payment_method.detached',
        'data' => ['object' => ['id' => $id, 'object' => 'payment_method']],
    ], []);
}

it('forgets the reference and reports Processed', function () {
    $gatewayId = GatewayId::generate();

    $eraser = Mockery::mock(InstrumentReferenceEraser::class);
    $eraser->shouldReceive('forgetPaymentMethodReference')
        ->once()
        ->with(Mockery::on(fn (GatewayId $g) => $g->toString() === $gatewayId->toString()), 'pm_abc')
        ->andReturnTrue();

    expect((new PaymentMethodDetachedHandler($eraser))(pmDetachedEvent(), $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('returns Skipped when nothing was erased', function () {
    $eraser = Mockery::mock(InstrumentReferenceEraser::class);
    $eraser->shouldReceive('forgetPaymentMethodReference')->andReturnFalse();

    expect((new PaymentMethodDetachedHandler($eraser))(pmDetachedEvent(), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('returns Skipped when the payload lacks an id', function () {
    $event = Util::convertToStripeObject([
        'id' => 'evt_1',
        'type' => 'payment_method.detached',
        'data' => ['object' => []],
    ], []);

    $eraser = Mockery::mock(InstrumentReferenceEraser::class);
    $eraser->shouldNotReceive('forgetPaymentMethodReference');

    expect((new PaymentMethodDetachedHandler($eraser))($event, GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});
