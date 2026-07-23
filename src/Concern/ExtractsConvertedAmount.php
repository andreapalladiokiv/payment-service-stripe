<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Concern;

use Money\Currency;
use Money\Money;
use Stripe\PaymentIntent;

/**
 * Extracts the FX-settled amount from a Stripe PaymentIntent whose
 * `latest_charge.balance_transaction` was expanded. The balance transaction
 * carries the amount in the merchant's settlement currency plus the
 * `exchange_rate` Stripe applied; when the rate is present the charge crossed
 * a currency boundary and the settlement amount is the converted figure.
 *
 * Returns null when no conversion happened — the balance transaction is
 * absent (authorize-only PI, not yet expanded) or `exchange_rate` is null
 * (presentment and settlement currency match). Null is the "no FX signal"
 * value the {@see \Techork\PaymentService\Gateway\Contract\ConvertedAmountProvider}
 * contract expects.
 *
 * Each call site must request `'expand' => ['latest_charge.balance_transaction']`
 * for this to populate.
 */
trait ExtractsConvertedAmount
{
    private function extractConvertedAmount(?PaymentIntent $paymentIntent): ?Money
    {
        $charge = $paymentIntent?->latest_charge;
        if (! $charge instanceof \Stripe\Charge) {
            return null;
        }

        $balanceTransaction = $charge->balance_transaction;
        if (! $balanceTransaction instanceof \Stripe\BalanceTransaction) {
            return null;
        }

        if ($balanceTransaction->exchange_rate === null) {
            return null;
        }

        return new Money(
            $balanceTransaction->amount,
            new Currency(strtoupper($balanceTransaction->currency)),
        );
    }
}
