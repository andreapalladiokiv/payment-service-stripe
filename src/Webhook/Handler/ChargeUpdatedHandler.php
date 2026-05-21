<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook\Handler;

use DateTimeImmutable;
use Money\Currency;
use Money\Money;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

/**
 * Stripe `charge.updated` — fires (among other things) when the charge's
 * `balance_transaction` becomes available, which is when we can read the
 * processor fee. We refetch the BalanceTransaction directly via the
 * Stripe SDK because the webhook payload only carries the BT id, not its
 * fields.
 *
 * Skipped if `balance_transaction` isn't on the payload yet (Stripe will
 * send another `charge.updated` later) or if our PI cannot be resolved
 * yet (caller delays).
 *
 * @implements WebhookEventHandler<Event>
 */
final readonly class ChargeUpdatedHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewayFeeRecorder $recorder,
        private GatewayCredentialRepository $credentialRepository,
    ) {}

    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var Event $event */
        $charge = $event->data->object;

        $paymentIntentReference = (string) ($charge->payment_intent ?? '');
        if ($paymentIntentReference === '') {
            return HandlerOutcome::Skipped;
        }

        $balanceTransactionId = self::stringOrNull($charge->balance_transaction ?? null);
        if ($balanceTransactionId === null) {
            // Stripe will retry — the BalanceTransaction is created
            // asynchronously a few seconds after the charge succeeds.
            return HandlerOutcome::Skipped;
        }

        $paymentIntentId = $this->resolver->resolvePaymentIntent($gatewayId, $paymentIntentReference);
        if ($paymentIntentId === null) {
            return HandlerOutcome::Delay;
        }

        $fee = $this->fetchFee($gatewayId, $balanceTransactionId);
        if ($fee === null) {
            return HandlerOutcome::Skipped;
        }

        return match ($this->recorder->onPaymentIntentFee($gatewayId, $paymentIntentId, $fee, new DateTimeImmutable)) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }

    private function fetchFee(GatewayId $gatewayId, string $balanceTransactionId): ?Money
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $apiKey = $credential->getCredentials()['api_key'] ?? null;
        if (! is_string($apiKey) || $apiKey === '') {
            return null;
        }

        try {
            $bt = (new \Stripe\StripeClient($apiKey))->balanceTransactions->retrieve($balanceTransactionId);
        } catch (ApiErrorException) {
            return null;
        }

        $amount = $bt->fee ?? null;
        $currency = $bt->currency ?? null;
        if (! is_int($amount) || ! is_string($currency) || $currency === '') {
            return null;
        }

        return new Money($amount, new Currency(strtoupper($currency)));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_object($value) && isset($value->id) && is_string($value->id) && $value->id !== '') {
            return $value->id;
        }

        return null;
    }
}
