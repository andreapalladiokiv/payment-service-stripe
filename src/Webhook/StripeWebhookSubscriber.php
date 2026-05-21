<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook;

use Techork\PaymentService\Stripe\Webhook\Handler\ChargeRefundedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeRefundUpdatedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeUpdatedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentIntentCanceledHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentIntentFailedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentIntentSucceededHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentMethodAttachedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentMethodDetachedHandler;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookSubscriber;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;

final readonly class StripeWebhookSubscriber implements WebhookSubscriber
{
    private const string KIND = 'Stripe';

    public function __construct(
        private SignatureVerifier $verifier,
        private EventParser $parser,
        private PaymentIntentSucceededHandler $paymentIntentSucceeded,
        private PaymentIntentCanceledHandler $paymentIntentCanceled,
        private PaymentIntentFailedHandler $paymentIntentFailed,
        private ChargeRefundedHandler $chargeRefunded,
        private ChargeUpdatedHandler $chargeUpdated,
        private ChargeRefundUpdatedHandler $chargeRefundUpdated,
        private PaymentMethodAttachedHandler $paymentMethodAttached,
        private PaymentMethodDetachedHandler $paymentMethodDetached,
    ) {}

    public function subscribe(VerifierRegistry $verifiers, HandlerRegistry $handlers): void
    {
        $verifiers->register(self::KIND, $this->verifier, $this->parser);

        $handlers->register(self::KIND, 'payment_intent.succeeded', $this->paymentIntentSucceeded);
        $handlers->register(self::KIND, 'payment_intent.canceled', $this->paymentIntentCanceled);
        $handlers->register(self::KIND, 'payment_intent.payment_failed', $this->paymentIntentFailed);
        $handlers->register(self::KIND, 'charge.refunded', $this->chargeRefunded);
        $handlers->register(self::KIND, 'charge.updated', $this->chargeUpdated);
        $handlers->register(self::KIND, 'charge.refund.updated', $this->chargeRefundUpdated);
        $handlers->register(self::KIND, 'payment_method.attached', $this->paymentMethodAttached);
        $handlers->register(self::KIND, 'payment_method.detached', $this->paymentMethodDetached);
    }
}
