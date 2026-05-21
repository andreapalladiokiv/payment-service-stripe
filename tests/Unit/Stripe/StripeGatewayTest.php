<?php

declare(strict_types=1);

use Techork\PaymentService\Stripe\AuthorizeRequest;
use Techork\PaymentService\Stripe\CaptureRequest;
use Techork\PaymentService\Stripe\CreateCardRequest;
use Techork\PaymentService\Stripe\CreateCustomerRequest;
use Techork\PaymentService\Stripe\CreatePaymentMethodRequest;
use Techork\PaymentService\Stripe\PurchaseRequest;
use Techork\PaymentService\Stripe\RefundRequest;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\UpdateCustomerRequest;
use Techork\PaymentService\Stripe\VoidRequest;

function makeStripeGateway(): StripeGateway
{
    $gateway = new StripeGateway;
    $gateway->initialize(['apiKey' => 'sk_test_fake']);

    return $gateway;
}

it('has name stripe', function () {
    expect(makeStripeGateway()->getName())->toBe('stripe');
});

it('initializes with apiKey', function () {
    expect(makeStripeGateway()->getApiKey())->toBe('sk_test_fake');
});

it('creates createCustomer request', function () {
    expect(makeStripeGateway()->createCustomer())->toBeInstanceOf(CreateCustomerRequest::class);
});

it('creates updateCustomer request', function () {
    expect(makeStripeGateway()->updateCustomer())->toBeInstanceOf(UpdateCustomerRequest::class);
});

it('creates createCard request', function () {
    expect(makeStripeGateway()->createCard())->toBeInstanceOf(CreateCardRequest::class);
});

it('creates createPaymentMethod request', function () {
    expect(makeStripeGateway()->createPaymentMethod())->toBeInstanceOf(CreatePaymentMethodRequest::class);
});

it('creates purchase request', function () {
    expect(makeStripeGateway()->purchase())->toBeInstanceOf(PurchaseRequest::class);
});

it('creates authorize request', function () {
    expect(makeStripeGateway()->authorize())->toBeInstanceOf(AuthorizeRequest::class);
});

it('creates capture request', function () {
    expect(makeStripeGateway()->capture())->toBeInstanceOf(CaptureRequest::class);
});

it('creates refund request', function () {
    expect(makeStripeGateway()->refund())->toBeInstanceOf(RefundRequest::class);
});

it('creates void request', function () {
    expect(makeStripeGateway()->void())->toBeInstanceOf(VoidRequest::class);
});
