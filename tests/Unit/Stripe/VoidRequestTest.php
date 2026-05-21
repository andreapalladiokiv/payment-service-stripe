<?php

declare(strict_types=1);

use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\VoidResponse;

function voidGateway(): StripeGateway
{
    $gw = new StripeGateway;
    $gw->initialize(['apiKey' => 'sk_test_fake']);

    return $gw;
}

it('builds void data with transactionReference', function () {
    $request = voidGateway()->void([
        'transactionReference' => 'pi_abc123',
    ]);

    $data = $request->getData();

    expect($data)->toBe(['payment_intent' => 'pi_abc123']);
});

it('throws when transactionReference is missing', function () {
    $request = voidGateway()->void();

    $request->getData();
})->throws(\Omnipay\Common\Exception\InvalidRequestException::class);

it('returns VoidResponse that is successful when status is canceled', function () {
    $request = voidGateway()->void([
        'transactionReference' => 'pi_abc123',
    ]);

    $response = new VoidResponse($request, [
        'reference' => 'pi_abc123',
        'status' => 'canceled',
        'error' => null,
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('pi_abc123')
        ->and($response->getMessage())->toBeNull();
});

it('returns VoidResponse that is not successful when status is not canceled', function () {
    $request = voidGateway()->void([
        'transactionReference' => 'pi_abc123',
    ]);

    $response = new VoidResponse($request, [
        'reference' => 'pi_abc123',
        'status' => 'requires_payment_method',
        'error' => null,
    ]);

    expect($response->isSuccessful())->toBeFalse();
});

it('returns VoidResponse with error on failure', function () {
    $request = voidGateway()->void([
        'transactionReference' => 'pi_abc123',
    ]);

    $response = new VoidResponse($request, [
        'reference' => null,
        'status' => null,
        'error' => 'No such payment intent',
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getTransactionReference())->toBeNull()
        ->and($response->getMessage())->toBe('No such payment intent');
});
