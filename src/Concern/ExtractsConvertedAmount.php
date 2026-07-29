<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Concern;

use Money\Currency;
use Money\Money;
use Stripe\PaymentIntent;

/**
 * Extracts the FX-settled amount from a Stripe PaymentIntent whose
 * `latest_charge.balance_transaction` was expanded. The balance transaction
 * carries the amount credited in the merchant's settlement currency.
 *
 * Whether a conversion happened is decided by comparing the charge's currency
 * with the balance transaction's, NOT by reading Stripe's `exchange_rate`. That
 * field is unreliable, and trusting it fails in both directions: absent on a
 * charge that really did convert, it loses the settled figure entirely; present
 * on one that did not, it reports a "converted" amount that is merely a copy of
 * the amount. The currencies cannot lie about it — the charge is denominated in
 * what the customer paid and the balance transaction in what the account
 * received, so a difference between them IS the conversion.
 *
 * Returns null when no conversion happened: the balance transaction is absent
 * (authorize-only PI, or not expanded), or presentment and settlement currency
 * match. Null is the "no FX signal" value the
 * {@see \Techork\PaymentService\Gateway\Contract\ConvertedAmountProvider}
 * contract expects, and it is what separates "no conversion" from "a conversion
 * we failed to capture".
 *
 * The rate itself is deliberately not derived here. Dividing the two amounts is
 * only correct once each is normalised to its own currency's major unit — ¥10,000
 * settling to $61.21 is a rate of 0.006121, not 0.6121 — so that belongs where
 * both Money objects and an ISO currency table are at hand.
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

        $settlementCurrency = strtoupper((string) $balanceTransaction->currency);
        $presentmentCurrency = strtoupper((string) ($charge->currency ?? $paymentIntent->currency ?? ''));

        // Unknown presentment currency means the comparison cannot be made, and an
        // unknown must not read as a difference — that would report a conversion on
        // every charge whose currency simply was not expanded.
        if ($presentmentCurrency === '' || $settlementCurrency === '') {
            return null;
        }

        if ($presentmentCurrency === $settlementCurrency) {
            return null;
        }

        return new Money(
            $balanceTransaction->amount,
            new Currency($settlementCurrency),
        );
    }
}
