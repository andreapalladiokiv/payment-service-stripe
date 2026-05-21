<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook;

use Psr\Http\Message\ServerRequestInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Stripe\WebhookSignature;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier as SignatureVerifierContract;

/**
 * Stripe signature verification. Pure protocol: validates the `Stripe-Signature`
 * header against the credential's `webhook_signing_key` (Stripe's "webhook
 * signing secret", `whsec_…`).
 */
final readonly class SignatureVerifier implements SignatureVerifierContract
{
    public function verify(ServerRequestInterface $request, GatewayCredential $gateway): bool
    {
        $signature = $request->getHeaderLine('Stripe-Signature');
        if ($signature === '') {
            return false;
        }

        $payload = $request->getBody()->getContents();
        $secret = $gateway->getCredentials()['webhook_signing_key'] ?? null;
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        try {
            WebhookSignature::verifyHeader($payload, $signature, $secret, Webhook::DEFAULT_TOLERANCE);

            return true;
        } catch (SignatureVerificationException) {
            return false;
        }
    }
}
