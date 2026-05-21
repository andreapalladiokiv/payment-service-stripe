<?php

declare(strict_types=1);

use Stripe\Util\Util;
use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentMethodAttachedHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

function pmAttachedEvent(array $overrides = []): object
{
    return Util::convertToStripeObject([
        'id' => 'evt_1',
        'type' => 'payment_method.attached',
        'data' => ['object' => array_replace_recursive([
            'id' => 'pm_abc',
            'object' => 'payment_method',
            'type' => 'card',
            'customer' => 'cus_xyz',
            'card' => [
                'brand' => 'visa',
                'iin' => '424242',
                'last4' => '4242',
                'exp_month' => 12,
                'exp_year' => 2030,
            ],
            'billing_details' => [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'address' => [
                    'line1' => '1 Main',
                    'city' => 'NYC',
                    'country' => 'US',
                    'postal_code' => '10001',
                ],
            ],
        ], $overrides)],
    ], []);
}

it('delegates to GatewayPaymentMethodRecorder with parsed card and billing address', function () {
    $gatewayId = GatewayId::generate();

    $recorder = Mockery::mock(GatewayPaymentMethodRecorder::class);
    $recorder->shouldReceive('onPaymentMethodRecord')
        ->once()
        ->withArgs(function (GatewayId $gw, string $cus, string $pmRef, CreditCard $card, BillingAddress $addr) use ($gatewayId): bool {
            return $gw->toString() === $gatewayId->toString()
                && $cus === 'cus_xyz'
                && $pmRef === 'pm_abc'
                && $card->number->last4 === '4242'
                && $addr->line === '1 Main';
        })
        ->andReturn(RecorderOutcome::Applied);

    expect((new PaymentMethodAttachedHandler($recorder))(pmAttachedEvent(), $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('returns Skipped when the payment method is not a card', function () {
    $recorder = Mockery::mock(GatewayPaymentMethodRecorder::class);
    $recorder->shouldNotReceive('onPaymentMethodRecord');

    expect((new PaymentMethodAttachedHandler($recorder))(pmAttachedEvent(['type' => 'us_bank_account']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('fills shredding stubs and records when billing address is incomplete', function () {
    $gatewayId = GatewayId::generate();

    $recorder = Mockery::mock(GatewayPaymentMethodRecorder::class);
    $recorder->shouldReceive('onPaymentMethodRecord')
        ->once()
        ->withArgs(function (GatewayId $gw, string $cus, string $pmRef, CreditCard $card, BillingAddress $addr): bool {
            return $addr->line === ShreddingStubs::ADDRESS_LINE
                && $addr->city === ShreddingStubs::CITY
                && (string) $addr->country === ShreddingStubs::COUNTRY
                && $addr->postalCode === ShreddingStubs::POSTAL_CODE;
        })
        ->andReturn(RecorderOutcome::Applied);

    expect((new PaymentMethodAttachedHandler($recorder))(pmAttachedEvent(['billing_details' => ['name' => '', 'address' => ['line1' => '', 'city' => '', 'country' => '', 'postal_code' => '']]]), $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('maps Skipped from the recorder straight through', function () {
    $recorder = Mockery::mock(GatewayPaymentMethodRecorder::class);
    $recorder->shouldReceive('onPaymentMethodRecord')->andReturn(RecorderOutcome::Skipped);

    expect((new PaymentMethodAttachedHandler($recorder))(pmAttachedEvent(), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});
