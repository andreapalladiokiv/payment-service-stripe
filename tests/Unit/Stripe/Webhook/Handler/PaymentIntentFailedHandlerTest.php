<?php

declare(strict_types=1);

use Stripe\Util\Util;

use Techork\PaymentService\Stripe\Webhook\Handler\PaymentIntentFailedHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

it('extracts reason from last_payment_error.message and delegates to the recorder', function () {
    $event = Util::convertToStripeObject([
        'id' => 'evt_1',
        'type' => 'payment_intent.payment_failed',
        'data' => ['object' => [
            'id' => 'pi_123',
            'object' => 'payment_intent',
            'last_payment_error' => ['message' => 'Card declined', 'code' => 'card_declined'],
        ]],
    ], []);

    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . uniqid();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn($piId);

    $recorder = Mockery::mock(GatewayFailureRecorder::class);
    $recorder->shouldReceive('onGatewayFailure')->once()->with($piId, 'Card declined')->andReturn(RecorderOutcome::Applied);

    expect((new PaymentIntentFailedHandler($resolver, $recorder))($event, $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('falls back to a generic reason when last_payment_error is absent', function () {
    $event = Util::convertToStripeObject([
        'id' => 'evt_1',
        'type' => 'payment_intent.payment_failed',
        'data' => ['object' => ['id' => 'pi_123', 'object' => 'payment_intent']],
    ], []);

    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-' . uniqid();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn($piId);

    $recorder = Mockery::mock(GatewayFailureRecorder::class);
    $recorder->shouldReceive('onGatewayFailure')
        ->once()
        ->with($piId, 'Payment failed at gateway')
        ->andReturn(RecorderOutcome::Applied);

    expect((new PaymentIntentFailedHandler($resolver, $recorder))($event, $gatewayId))->toBe(HandlerOutcome::Processed);
});
