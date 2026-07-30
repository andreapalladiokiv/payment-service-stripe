<?php

declare(strict_types=1);

use Money\Money;
use Stripe\Util\Util;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentIntentRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\NoOpGatewayPaymentIntentRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentIntentCreatedHandler;

/**
 * @param  array<string, mixed>  $overrides
 */
function createdEvent(array $overrides = []): object
{
    return Util::convertToStripeObject([
        'id' => 'evt_1',
        'type' => 'payment_intent.created',
        'data' => ['object' => $overrides + [
            'id' => 'pi_123',
            'object' => 'payment_intent',
            'amount' => 5000,
            'currency' => 'usd',
            'status' => 'requires_payment_method',
        ]],
    ], []);
}

it('records the intent the gateway has just opened', function () {
    $gatewayId = GatewayId::generate();

    $recorder = Mockery::mock(GatewayPaymentIntentRecorder::class);
    $recorder->shouldReceive('onPaymentIntentRecord')
        ->once()
        ->with(
            $gatewayId,
            'pi_123',
            Mockery::on(fn (Money $m) => $m->getAmount() === '5000' && $m->getCurrency()->getCode() === 'USD'),
            null,
            null,
            null,
        )
        ->andReturn(RecorderOutcome::Applied);

    $handler = new PaymentIntentCreatedHandler($recorder);

    expect($handler(createdEvent(), $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('books the amount it intends to take, not what it has received', function () {
    // `amount_received` is 0 on a fresh intent, and the sibling handlers prefer
    // it. Preferring it here would open every local aggregate at zero.
    $gatewayId = GatewayId::generate();

    $recorder = Mockery::mock(GatewayPaymentIntentRecorder::class);
    $recorder->shouldReceive('onPaymentIntentRecord')
        ->once()
        ->with(
            $gatewayId,
            'pi_123',
            Mockery::on(fn (Money $m) => $m->getAmount() === '5000'),
            null,
            null,
            null,
        )
        ->andReturn(RecorderOutcome::Applied);

    $handler = new PaymentIntentCreatedHandler($recorder);

    $handler(createdEvent(['amount_received' => 0]), $gatewayId);
});

it('refuses to book an intent that names no currency instead of assuming USD', function () {
    $recorder = Mockery::mock(GatewayPaymentIntentRecorder::class);
    $recorder->shouldNotReceive('onPaymentIntentRecord');

    $handler = new PaymentIntentCreatedHandler($recorder);

    expect(fn () => $handler(createdEvent(['currency' => '']), GatewayId::generate()))
        ->toThrow(RuntimeException::class, 'names no currency');
});

it('skips an event that names no intent', function () {
    $recorder = Mockery::mock(GatewayPaymentIntentRecorder::class);
    $recorder->shouldNotReceive('onPaymentIntentRecord');

    $handler = new PaymentIntentCreatedHandler($recorder);

    expect($handler(createdEvent(['id' => '']), GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});

it('passes the payment method along whether Stripe sends an id or the object', function (mixed $paymentMethod) {
    // Endpoints configured to expand receive the object. Reading only the string
    // form would silently drop the reference on exactly those accounts.
    $gatewayId = GatewayId::generate();

    $recorder = Mockery::mock(GatewayPaymentIntentRecorder::class);
    $recorder->shouldReceive('onPaymentIntentRecord')
        ->once()
        ->with($gatewayId, 'pi_123', Mockery::type(Money::class), 'pm_456', null, null)
        ->andReturn(RecorderOutcome::Applied);

    $handler = new PaymentIntentCreatedHandler($recorder);

    $handler(createdEvent(['payment_method' => $paymentMethod]), $gatewayId);
})->with([
    'bare id' => 'pm_456',
    'expanded object' => [['id' => 'pm_456', 'object' => 'payment_method']],
]);

it('carries the description and statement descriptor through', function () {
    $gatewayId = GatewayId::generate();

    $recorder = Mockery::mock(GatewayPaymentIntentRecorder::class);
    $recorder->shouldReceive('onPaymentIntentRecord')
        ->once()
        ->with($gatewayId, 'pi_123', Mockery::type(Money::class), null, 'Order 41', 'ACME')
        ->andReturn(RecorderOutcome::Applied);

    $handler = new PaymentIntentCreatedHandler($recorder);

    $handler(createdEvent(['description' => 'Order 41', 'statement_descriptor' => 'ACME']), $gatewayId);
});

it('reports blank text as absent rather than as a deliberate blanking', function (string $blank) {
    $gatewayId = GatewayId::generate();

    $recorder = Mockery::mock(GatewayPaymentIntentRecorder::class);
    $recorder->shouldReceive('onPaymentIntentRecord')
        ->once()
        ->with($gatewayId, 'pi_123', Mockery::type(Money::class), null, null, null)
        ->andReturn(RecorderOutcome::Applied);

    $handler = new PaymentIntentCreatedHandler($recorder);

    $handler(createdEvent(['description' => $blank, 'statement_descriptor' => $blank]), $gatewayId);
})->with([
    'empty' => '',
    'whitespace' => '   ',
]);

it('translates every recorder outcome', function (RecorderOutcome $outcome, HandlerOutcome $expected) {
    // Skipped is the case that matters most: our own API creating the intent
    // makes Stripe send this event too, and being told what we already know has
    // to ack rather than retry.
    $gatewayId = GatewayId::generate();

    $recorder = Mockery::mock(GatewayPaymentIntentRecorder::class);
    $recorder->shouldReceive('onPaymentIntentRecord')->once()->andReturn($outcome);

    $handler = new PaymentIntentCreatedHandler($recorder);

    expect($handler(createdEvent(), $gatewayId))->toBe($expected);
})->with([
    'applied' => [RecorderOutcome::Applied, HandlerOutcome::Processed],
    'already recorded' => [RecorderOutcome::Skipped, HandlerOutcome::Skipped],
    'blocked on something unknown' => [RecorderOutcome::NotFound, HandlerOutcome::Delay],
]);

it('does nothing at all under the default no-op recorder', function () {
    // The bridge binds the no-op, so adding this handler cannot change the
    // behaviour of a consumer that has not opted in — and the event acks
    // instead of retrying ten times.
    $handler = new PaymentIntentCreatedHandler(new NoOpGatewayPaymentIntentRecorder());

    expect($handler(createdEvent(), GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});
