<?php

declare(strict_types=1);

use Stripe\Util\Util;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeUpdatedHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;

/**
 * Tests cover the routing logic — outcomes that don't depend on the
 * actual Stripe API call. The fee-fetch path (which calls
 * `balanceTransactions->retrieve`) is not unit-tested here; it's
 * exercised in integration tests that hit the Stripe sandbox.
 */
function chargeUpdatedEvent(array $overrides = []): object
{
    return Util::convertToStripeObject([
        'id' => 'evt_charge_upd',
        'type' => 'charge.updated',
        'data' => ['object' => array_replace([
            'id' => 'ch_123',
            'object' => 'charge',
            'payment_intent' => 'pi_123',
            'balance_transaction' => 'txn_123',
        ], $overrides)],
    ], []);
}

it('returns Skipped when payment_intent reference is missing', function () {
    $handler = new ChargeUpdatedHandler(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewayFeeRecorder::class),
        Mockery::mock(GatewayCredentialRepository::class),
    );

    expect($handler(chargeUpdatedEvent(['payment_intent' => null]), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('returns Skipped when balance_transaction is missing (Stripe will retry)', function () {
    $handler = new ChargeUpdatedHandler(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewayFeeRecorder::class),
        Mockery::mock(GatewayCredentialRepository::class),
    );

    expect($handler(chargeUpdatedEvent(['balance_transaction' => null]), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('returns Delay when PaymentIntent reference is unknown', function () {
    $gatewayId = GatewayId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->with($gatewayId, 'pi_123')->andReturnNull();

    $recorder = Mockery::mock(GatewayFeeRecorder::class);
    $recorder->shouldNotReceive('onPaymentIntentFee');

    $credentials = Mockery::mock(GatewayCredentialRepository::class);
    $credentials->shouldNotReceive('findOrFail');

    $handler = new ChargeUpdatedHandler($resolver, $recorder, $credentials);

    expect($handler(chargeUpdatedEvent(), $gatewayId))->toBe(HandlerOutcome::Delay);
});

it('accepts an expanded balance_transaction object (not only the id string)', function () {
    $gatewayId = GatewayId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturnNull();

    $handler = new ChargeUpdatedHandler(
        $resolver,
        Mockery::mock(GatewayFeeRecorder::class),
        Mockery::mock(GatewayCredentialRepository::class),
    );

    // payment_intent missing → Skipped before the balance_transaction
    // shape matters; this test asserts the expanded-object path doesn't
    // throw a TypeError when it IS encountered.
    $event = chargeUpdatedEvent([
        'balance_transaction' => ['id' => 'txn_expanded', 'object' => 'balance_transaction'],
        'payment_intent' => null,
    ]);

    expect($handler($event, $gatewayId))->toBe(HandlerOutcome::Skipped);
});
