<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Techork\PaymentService\Stripe\Concern\ExtractsConvertedAmount;

/**
 * Conversion is detected by comparing the charge's currency with the balance
 * transaction's, never by reading Stripe's `exchange_rate`.
 *
 * Every case here sets `exchange_rate` to a value that CONTRADICTS the currencies,
 * because a test that agreed with the field would pass whether the implementation
 * read it or not — and ruling that out is the point.
 */
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

function paymentIntentCharged(
    string $presentmentCurrency,
    int $settledAmount,
    string $settlementCurrency,
    ?float $exchangeRate = null,
): PaymentIntent {
    return PaymentIntent::constructFrom([
        'currency' => $presentmentCurrency,
        'latest_charge' => Charge::constructFrom([
            'currency' => $presentmentCurrency,
            'balance_transaction' => BalanceTransaction::constructFrom([
                'amount' => $settledAmount,
                'currency' => $settlementCurrency,
                'exchange_rate' => $exchangeRate,
            ]),
        ]),
    ]);
}

it('reads the settlement amount when presentment and settlement currency differ', function () {
    // exchange_rate is null even though the charge plainly crossed a boundary —
    // the Stripe defect this must survive. Reading the field would lose the
    // settled figure entirely.
    $paymentIntent = paymentIntentCharged(
        presentmentCurrency: 'eur',
        settledAmount: 5712,
        settlementCurrency: 'usd',
        exchangeRate: null,
    );

    expect(convertedAmountExtractor()->extract($paymentIntent))
        ->toEqual(new Money(5712, new Currency('USD')));
});

it('returns null when the charge settled in its own currency', function () {
    // exchange_rate is present and plausible, but nothing converted. Reading the
    // field would record a "converted" amount that is just a copy of the amount,
    // making a payment that never crossed a boundary indistinguishable from one
    // whose conversion was never captured.
    $paymentIntent = paymentIntentCharged(
        presentmentCurrency: 'usd',
        settledAmount: 5000,
        settlementCurrency: 'usd',
        exchangeRate: 1.14244,
    );

    expect(convertedAmountExtractor()->extract($paymentIntent))->toBeNull();
});

it('treats a case difference as the same currency', function () {
    // Stripe reports lowercase; a stored code is upper. A naive comparison would
    // report a conversion on every single charge.
    $paymentIntent = PaymentIntent::constructFrom([
        'currency' => 'USD',
        'latest_charge' => Charge::constructFrom([
            'currency' => 'usd',
            'balance_transaction' => BalanceTransaction::constructFrom([
                'amount' => 5000,
                'currency' => 'usd',
            ]),
        ]),
    ]);

    expect(convertedAmountExtractor()->extract($paymentIntent))->toBeNull();
});

it('falls back to the intent currency when the charge does not carry one', function () {
    $paymentIntent = PaymentIntent::constructFrom([
        'currency' => 'gbp',
        'latest_charge' => Charge::constructFrom([
            'balance_transaction' => BalanceTransaction::constructFrom([
                'amount' => 6400,
                'currency' => 'usd',
            ]),
        ]),
    ]);

    expect(convertedAmountExtractor()->extract($paymentIntent))
        ->toEqual(new Money(6400, new Currency('USD')));
});

it('returns null when the presentment currency is unknown', function () {
    // An unknown must not read as a difference: that would report a conversion on
    // every charge whose currency simply was not expanded.
    $paymentIntent = PaymentIntent::constructFrom([
        'latest_charge' => Charge::constructFrom([
            'balance_transaction' => BalanceTransaction::constructFrom([
                'amount' => 5000,
                'currency' => 'usd',
            ]),
        ]),
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

// ──────────────────────────────────────────────
//  absent properties must stay quiet
// ──────────────────────────────────────────────

/**
 * Absent is the normal case here — an authorize-only intent has no charge, an
 * unexpanded charge has no balance transaction — so the reads have to use isset
 * semantics. A bare `$obj->missing` reaches `StripeObject::__get`, which logs
 * "Stripe Notice: Undefined property of …" and returns null: the behaviour stays
 * correct while the log fills with noise on every ordinary authorization. That
 * makes it invisible to any test that only asserts the return value, which is
 * how it got in.
 */
function stripeNoticesWhile(callable $body): array
{
    $collector = new class implements Stripe\Util\LoggerInterface
    {
        /** @var list<string> */
        public array $messages = [];

        public function error($message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }
    };

    $previous = Stripe\Stripe::getLogger();
    Stripe\Stripe::setLogger($collector);

    try {
        $body();
    } finally {
        Stripe\Stripe::setLogger($previous);
    }

    return $collector->messages;
}

it('logs no Stripe notice when the payment intent has no latest_charge', function () {
    $messages = stripeNoticesWhile(function (): void {
        expect(convertedAmountExtractor()->extract(PaymentIntent::constructFrom(['id' => 'pi_1'])))->toBeNull();
    });

    expect($messages)->toBe([]);
});

it('logs no Stripe notice when the charge has no expanded balance_transaction', function () {
    $messages = stripeNoticesWhile(function (): void {
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_1',
            'currency' => 'usd',
            'latest_charge' => Charge::constructFrom(['id' => 'ch_1']),
        ]);

        expect(convertedAmountExtractor()->extract($paymentIntent))->toBeNull();
    });

    expect($messages)->toBe([]);
});

it('logs no Stripe notice when the balance transaction omits amount or currency', function (array $balanceTransaction) {
    $messages = stripeNoticesWhile(function () use ($balanceTransaction): void {
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_1',
            'currency' => 'usd',
            'latest_charge' => Charge::constructFrom([
                'id' => 'ch_1',
                'currency' => 'usd',
                'balance_transaction' => BalanceTransaction::constructFrom($balanceTransaction),
            ]),
        ]);

        expect(convertedAmountExtractor()->extract($paymentIntent))->toBeNull();
    });

    expect($messages)->toBe([]);
})->with([
    'no amount' => [['id' => 'txn_1', 'currency' => 'eur']],
    'no currency' => [['id' => 'txn_1', 'amount' => 900]],
    'neither' => [['id' => 'txn_1']],
]);

it('still reports the conversion when everything is present', function () {
    $messages = stripeNoticesWhile(function (): void {
        $converted = convertedAmountExtractor()->extract(paymentIntentCharged('jpy', 6121, 'usd'));

        expect($converted)->toEqual(new Money(6121, new Currency('USD')));
    });

    expect($messages)->toBe([]);
});
