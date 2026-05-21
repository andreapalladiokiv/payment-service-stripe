<?php

declare(strict_types=1);

use Stripe\Util\Util;

use Techork\PaymentService\Stripe\Webhook\Handler\PaymentIntentCanceledHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayCancellationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

function canceledEvent(): object
{
    return Util::convertToStripeObject([
        'id' => 'evt_1',
        'type' => 'payment_intent.canceled',
        'data' => ['object' => ['id' => 'pi_123', 'object' => 'payment_intent']],
    ], []);
}

it('delegates to GatewayCancellationRecorder on a known PaymentIntent', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . uniqid();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->with($gatewayId, 'pi_123')->andReturn($piId);

    $recorder = Mockery::mock(GatewayCancellationRecorder::class);
    $recorder->shouldReceive('onGatewayCancellation')->once()->with($piId)->andReturn(RecorderOutcome::Applied);

    expect((new PaymentIntentCanceledHandler($resolver, $recorder))(canceledEvent(), $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('returns Delay when the PI is unknown', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturnNull();

    $recorder = Mockery::mock(GatewayCancellationRecorder::class);
    $recorder->shouldNotReceive('onGatewayCancellation');

    expect((new PaymentIntentCanceledHandler($resolver, $recorder))(canceledEvent(), GatewayId::generate()))
        ->toBe(HandlerOutcome::Delay);
});
