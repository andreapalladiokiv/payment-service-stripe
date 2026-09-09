<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Concern;

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Customer;

/**
 * Shaping our value objects into the blocks Stripe's API expects.
 *
 * All that is left of a trait that was mostly bag-backed accessors. Every value they wrapped now
 * arrives through a constructor — the command's, or {@see \Techork\PaymentService\Stripe\StripeSettings} —
 * and the setters that existed only so Omnipay's initializer would not drop a key have gone with
 * the initializer. `setBillingAddress` was one of them, and its absence had already cost every
 * Stripe payment its billing details.
 */
trait StripeRequestParameters
{
    /**
     * The `address` block of a Stripe Customer, or null when there is nothing to say.
     *
     * Null rather than an empty array on purpose: Stripe reads `address: {}` as an instruction to
     * CLEAR the stored address, so sending one for a customer we simply know nothing new about
     * would erase what is already there.
     *
     * The shape is spelled out rather than left as `array<string, string>`, because Stripe's SDK
     * declares an exhaustive shape for the `address` block and a looser type reaches the call as
     * a coercion — which is how a wrong key name would get past static analysis on the way to an
     * API that drops it silently.
     *
     * @return array{line1?: string, city?: string, country?: string, postal_code?: string, state?: string}|null
     */
    protected function formatCustomerAddress(?BillingAddress $address): ?array
    {
        // The marker counts as absent, which is the whole reason it exists. A `Customer` always
        // has an address now, so `null` no longer reaches here from a caller that simply has no
        // address to give — it says so with `BillingAddress::unknown()` instead, and sending that
        // would blank a real address on the Stripe record with `ZZ` and a stub.
        if ($address === null || $address->isUnknown()) {
            return null;
        }

        return array_filter([
            'line1' => $address->line,
            'city' => $address->city,
            'country' => (string) $address->country,
            'postal_code' => $address->postalCode,
            'state' => $address->state !== null ? (string) $address->state : null,
        ]) ?: null;
    }

    /**
     * The `billing_details` block of a payment method: who the card belongs to and where they are.
     * What the issuer runs AVS and the postal-code check against.
     *
     * A whole {@see Customer}, because the block genuinely is both halves — `name`, `email` and
     * `phone` beside an `address` — and the person half used to be read off the
     * {@see BillingAddress}, which is what made the address stand in for the payer.
     *
     * @return array<string, mixed>|null
     */
    protected function formatBillingDetails(?Customer $customer): ?array
    {
        if ($customer === null) {
            return null;
        }

        $identity = $customer->identity;
        $address = $customer->billingAddress;

        // Stripe wants one name string; we hold two fields, because a provider that asks for them
        // separately (Nuvei does) cannot be served from a joined one.
        $name = trim($identity->firstName.' '.$identity->lastName);

        return array_filter([
            'name' => $name !== '' ? $name : null,
            'email' => $identity->email ? (string) $identity->email : null,
            'phone' => $identity->phone ? (string) $identity->phone : null,
            'address' => array_filter([
                'line1' => $address->line,
                'line2' => $address->lineExtra !== '' ? $address->lineExtra : null,
                'city' => $address->city,
                'state' => $address->state ? (string) $address->state : null,
                'postal_code' => $address->postalCode,
                'country' => (string) $address->country,
            ], static fn ($v) => $v !== null && $v !== ''),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Stripe's per-request options, carrying the caller's idempotency key when there is one.
     *
     * @return array{idempotency_key?: non-empty-string} the shape Stripe's services declare for
     *   their options argument; a bare `array` made every call site an unverifiable coercion
     */
    protected function stripeOpts(?string $clientUniqueId, ?string $scope = null): array
    {
        if ($clientUniqueId === null || $clientUniqueId === '') {
            return [];
        }

        return ['idempotency_key' => $scope === null ? $clientUniqueId : $clientUniqueId.':'.$scope];
    }
}
