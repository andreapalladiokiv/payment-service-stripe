<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Stripe\WebhookSignature;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Stripe\Webhook\SignatureVerifier;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function stripeCredential(string $secret): GatewayCredential
{
    return new class($secret) implements GatewayCredential
    {
        public function __construct(private readonly string $secret) {}

        public function getId(): GatewayId
        {
            return GatewayId::generate();
        }

        public function getGatewayName(): string
        {
            return 'Stripe';
        }

        public function getCredentials(): array
        {
            return ['webhook_signing_key' => $this->secret];
        }
    };
}

function signedRequest(string $body, string $secret): Psr\Http\Message\ServerRequestInterface
{
    $timestamp = time();
    $signed = $timestamp.'.'.$body;
    $signature = hash_hmac('sha256', $signed, $secret);
    $header = "t={$timestamp},v1={$signature}";

    return (new Psr17Factory)
        ->createServerRequest('POST', '/webhooks')
        ->withHeader('Stripe-Signature', $header)
        ->withBody((new Psr17Factory)->createStream($body));
}

it('accepts a correctly signed Stripe webhook', function () {
    $secret = 'whsec_test';
    $body = '{"id":"evt_1","type":"payment_intent.succeeded"}';

    expect((new SignatureVerifier)->verify(signedRequest($body, $secret), stripeCredential($secret)))->toBeTrue();
});

it('rejects when the signing secret is wrong', function () {
    $body = '{"id":"evt_1"}';

    expect((new SignatureVerifier)->verify(signedRequest($body, 'good'), stripeCredential('bad')))->toBeFalse();
});

it('rejects when the Stripe-Signature header is missing', function () {
    $request = (new Psr17Factory)->createServerRequest('POST', '/webhooks');

    expect((new SignatureVerifier)->verify($request, stripeCredential('whsec_test')))->toBeFalse();
});

it('rejects when the credential lacks a webhook_signing_key', function () {
    $credential = new class implements GatewayCredential
    {
        public function getId(): GatewayId
        {
            return GatewayId::generate();
        }

        public function getGatewayName(): string
        {
            return 'Stripe';
        }

        public function getCredentials(): array
        {
            return [];
        }
    };

    $body = '{}';
    $timestamp = time();
    $sig = hash_hmac('sha256', "{$timestamp}.{$body}", 'x');
    $request = (new Psr17Factory)
        ->createServerRequest('POST', '/webhooks')
        ->withHeader('Stripe-Signature', "t={$timestamp},v1={$sig}")
        ->withBody((new Psr17Factory)->createStream($body));

    expect((new SignatureVerifier)->verify($request, $credential))->toBeFalse();
});
