<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Techork\PaymentService\Stripe\Concern\ExtractsConvertedAmount;

function convertedAmountExtractor(): object
{
    return new class
    {
        use ExtractsConvertedAmount;

        public function extract(?PaymentIntent $paymentIntent): ?Money
        {
            return $this->extractConvertedAmount($paymentIntent);
        }
    };
}

function paymentIntentWithBalanceTransaction(array $balanceTransaction): PaymentIntent
{
    return PaymentIntent::constructFrom([
        'latest_charge' => Charge::constructFrom([
            'balance_transaction' => BalanceTransaction::constructFrom($balanceTransaction),
        ]),
    ]);
}

it('reads the settlement amount when the balance transaction carries an exchange_rate', function () {
    $paymentIntent = paymentIntentWithBalanceTransaction([
        'amount' => 5712,
        'currency' => 'usd',
        'exchange_rate' => 1.14244,
    ]);

    expect(convertedAmountExtractor()->extract($paymentIntent))
        ->toEqual(new Money(5712, new Currency('USD')));
});

it('returns null when no exchange_rate was applied (presentment == settlement)', function () {
    $paymentIntent = paymentIntentWithBalanceTransaction([
        'amount' => 5000,
        'currency' => 'usd',
        'exchange_rate' => null,
    ]);

    expect(convertedAmountExtractor()->extract($paymentIntent))->toBeNull();
});

it('returns null when the payment intent has no latest_charge (authorize-only)', function () {
    $paymentIntent = PaymentIntent::constructFrom(['id' => 'pi_1', 'status' => 'requires_capture']);

    expect(convertedAmountExtractor()->extract($paymentIntent))->toBeNull();
});

it('returns null when the charge has no expanded balance_transaction', function () {
    $paymentIntent = PaymentIntent::constructFrom([
        'latest_charge' => Charge::constructFrom(['id' => 'ch_1']),
    ]);

    expect(convertedAmountExtractor()->extract($paymentIntent))->toBeNull();
});

it('returns null for a null payment intent', function () {
    expect(convertedAmountExtractor()->extract(null))->toBeNull();
});
