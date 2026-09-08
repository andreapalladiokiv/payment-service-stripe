<?php

declare(strict_types=1);

use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;

/**
 * Verifies the {@see StripeRequestParameters::stripeOpts} helper that builds the Stripe SDK opts
 * array carrying `idempotency_key`.
 *
 * Exercised through a purpose-built host rather than through an operation class. It used to go
 * through `PurchaseRequest`, because the id came out of that class's parameter bag and there was
 * no other way to supply one; it is an argument now, so nothing about an operation participates
 * in the mapping and the host is the same shape ExtractsCardChecksTest uses.
 */
function stripeOptsHost(): object
{
    return new class
    {
        use StripeRequestParameters;

        /** @return array<string, string> */
        public function opts(?string $clientUniqueId, ?string $scope = null): array
        {
            return $this->stripeOpts($clientUniqueId, $scope);
        }
    };
}

it('returns empty opts when clientUniqueId is null', function () {
    expect(stripeOptsHost()->opts(null))->toBe([]);
});

it('returns empty opts when clientUniqueId is empty string', function () {
    expect(stripeOptsHost()->opts(''))->toBe([]);
});

it('emits idempotency_key opt when clientUniqueId is set', function () {
    expect(stripeOptsHost()->opts('pi-uuid-7'))->toBe(['idempotency_key' => 'pi-uuid-7']);
});

it('derives a distinct key per endpoint scope', function () {
    $host = stripeOptsHost();

    expect($host->opts('pm-uuid-3', 'payment_method'))->toBe(['idempotency_key' => 'pm-uuid-3:payment_method'])
        ->and($host->opts('pm-uuid-3', 'setup_intent'))->toBe(['idempotency_key' => 'pm-uuid-3:setup_intent'])
        ->and($host->opts('pm-uuid-3'))->toBe(['idempotency_key' => 'pm-uuid-3']);
});

it('keeps a scoped key stable so a retry still deduplicates', function () {
    expect(stripeOptsHost()->opts('pm-uuid-3', 'setup_intent'))
        ->toBe(stripeOptsHost()->opts('pm-uuid-3', 'setup_intent'));
});

it('stays empty under a scope when there is no id to derive from', function () {
    expect(stripeOptsHost()->opts(null, 'setup_intent'))->toBe([]);
});

/**
 * Stripe binds an idempotency key to the endpoint that first used it, so two calls
 * in one operation class must not share one. The id is stable, which makes such a
 * collision permanent rather than transient: every retry burns on it and the
 * operation can never complete. The failure surfaces far from here — as an
 * instrument that is never registered — so it is worth catching structurally.
 */
it('never hands the same idempotency key to two Stripe endpoints', function () {
    $offenders = [];

    foreach (glob(__DIR__.'/../../../../src/*.php') ?: [] as $file) {
        preg_match_all('/\$this->stripeOpts\(([^)]*)\)/', (string) file_get_contents($file), $matches);

        $scopes = array_map(trim(...), $matches[1]);
        if (count($scopes) > 1 && count(array_unique($scopes)) !== count($scopes)) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

/*
 * `it('round-trips the clientUniqueId via getter/setter')` lived here. The id was a key in
 * omnipay's parameter bag with a getter and a setter over it; it is a field of the command now,
 * and the operations read it straight off. There is nothing to round-trip.
 */
