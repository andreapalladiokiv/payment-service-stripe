<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Concern;

use Money\Currency;
use Money\Money;
use Stripe\BalanceTransaction;
use Stripe\Charge;
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
        // Every read here goes through `??` on purpose. A bare `$obj->missing`
        // on a Stripe object reaches StripeObject::__get, which logs
        // "Stripe Notice: Undefined property of …" before returning null
        // (vendor/stripe/stripe-php/lib/StripeObject.php:191). `??` uses isset
        // semantics, so __isset answers from the value bag and __get is never
        // called. Absent is the normal case for this trait — an authorize-only
        // intent has no charge, an unexpanded charge has no balance transaction
        // — so reading them bare turns routine null-checks into log noise.
        $charge = $paymentIntent?->latest_charge ?? null;
        if (! $charge instanceof Charge) {
            return null;
        }

        $balanceTransaction = $charge->balance_transaction ?? null;
        if (! $balanceTransaction instanceof BalanceTransaction) {
            return null;
        }

        $settlementAmount = $balanceTransaction->amount ?? null;
        if (! is_int($settlementAmount)) {
            return null;
        }

        $settlementCurrency = strtoupper($balanceTransaction->currency ?? '');
        $presentmentCurrency = strtoupper($charge->currency ?? $paymentIntent->currency ?? '');

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
            $settlementAmount,
            new Currency($settlementCurrency),
        );
    }
}
