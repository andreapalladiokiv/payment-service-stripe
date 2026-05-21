<?php

declare(strict_types=1);

use Money\Money;
use Stripe\Util\Util;

use Techork\PaymentService\Stripe\Webhook\Handler\PaymentIntentSucceededHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

function succeededEvent(string $piReference = 'pi_123', int $amountReceived = 5000, string $currency = 'usd'): object
{
    return Util::convertToStripeObject([
        'id' => 'evt_1',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => $piReference,
            'object' => 'payment_intent',
            'amount_received' => $amountReceived,
            'currency' => $currency,
        ]],
    ], []);
}

it('delegates to GatewaySuccessRecorder on a known PaymentIntent', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . uniqid();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->with($gatewayId, 'pi_123')->andReturn($piId);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')
        ->once()
        ->with($gatewayId, $piId, 'pi_123', Mockery::on(fn (Money $m) => $m->getAmount() === '5000' && $m->getCurrency()->getCode() === 'USD'))
        ->andReturn(RecorderOutcome::Applied);

    $handler = new PaymentIntentSucceededHandler($resolver, $recorder);

    expect($handler(succeededEvent(), $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('returns Delay when the PI reference is unknown', function () {
    $gatewayId = GatewayId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturnNull();

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldNotReceive('onGatewaySuccess');

    $handler = new PaymentIntentSucceededHandler($resolver, $recorder);

    expect($handler(succeededEvent(), $gatewayId))->toBe(HandlerOutcome::Delay);
});

it('returns Skipped when the payload lacks a PaymentIntent id', function () {
    $event = Util::convertToStripeObject([
        'id' => 'evt_x',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => []],
    ], []);

    $handler = new PaymentIntentSucceededHandler(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewaySuccessRecorder::class),
    );

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});

it('maps RecorderOutcome::NotFound to HandlerOutcome::Delay', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . uniqid();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn($piId);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')->andReturn(RecorderOutcome::NotFound);

    $handler = new PaymentIntentSucceededHandler($resolver, $recorder);

    expect($handler(succeededEvent(), $gatewayId))->toBe(HandlerOutcome::Delay);
});
