<?php

declare(strict_types=1);

use Stripe\Util\Util;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeRefundUpdatedHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;

function chargeRefundUpdatedEvent(array $overrides = []): object
{
    return Util::convertToStripeObject([
        'id' => 'evt_refund_upd',
        'type' => 'charge.refund.updated',
        'data' => ['object' => array_replace([
            'id' => 're_abc',
            'object' => 'refund',
            'balance_transaction' => 'txn_re_1',
        ], $overrides)],
    ], []);
}

it('returns Skipped when refund id is missing', function () {
    $handler = new ChargeRefundUpdatedHandler(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewayFeeRecorder::class),
        Mockery::mock(GatewayCredentialRepository::class),
    );

    expect($handler(chargeRefundUpdatedEvent(['id' => null]), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('returns Skipped when balance_transaction is missing', function () {
    $handler = new ChargeRefundUpdatedHandler(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewayFeeRecorder::class),
        Mockery::mock(GatewayCredentialRepository::class),
    );

    expect($handler(chargeRefundUpdatedEvent(['balance_transaction' => null]), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('returns Delay when the refund reference is unknown', function () {
    $gatewayId = GatewayId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolveRefund')->with($gatewayId, 're_abc')->andReturnNull();

    $recorder = Mockery::mock(GatewayFeeRecorder::class);
    $recorder->shouldNotReceive('onRefundFee');

    $handler = new ChargeRefundUpdatedHandler(
        $resolver,
        $recorder,
        Mockery::mock(GatewayCredentialRepository::class),
    );

    expect($handler(chargeRefundUpdatedEvent(), $gatewayId))->toBe(HandlerOutcome::Delay);
});
