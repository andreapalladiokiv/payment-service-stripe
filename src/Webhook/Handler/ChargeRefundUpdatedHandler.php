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
 * Stripe `charge.refund.updated` — fires when the refund's
 * `balance_transaction` becomes available, which is when we can read the
 * fee Stripe charges (or refunds) for that refund.
 *
 * Same delay-and-retry semantics as {@see ChargeUpdatedHandler}: skip if
 * BT isn't on the payload yet; delay if the refund hasn't been observed
 * locally.
 *
 * @implements WebhookEventHandler<Event>
 */
final readonly class ChargeRefundUpdatedHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewayFeeRecorder $recorder,
        private GatewayCredentialRepository $credentialRepository,
    ) {}

    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var Event $event */
        $refund = $event->data->object;

        $refundReference = (string) ($refund->id ?? '');
        if ($refundReference === '') {
            return HandlerOutcome::Skipped;
        }

        $balanceTransactionId = self::stringOrNull($refund->balance_transaction ?? null);
        if ($balanceTransactionId === null) {
            return HandlerOutcome::Skipped;
        }

        $refundId = $this->resolver->resolveRefund($gatewayId, $refundReference);
        if ($refundId === null) {
            return HandlerOutcome::Delay;
        }

        $fee = $this->fetchFee($gatewayId, $balanceTransactionId);
        if ($fee === null) {
            return HandlerOutcome::Skipped;
        }

        return match ($this->recorder->onRefundFee($gatewayId, $refundId, $fee, new DateTimeImmutable)) {
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
