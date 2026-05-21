<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Concern;

use Stripe\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;

/**
 * Extracts AVS / CVC verification outcomes from an expanded Stripe
 * PaymentMethod object into a flat array suitable for response data:
 *
 *   ['address_line_check' => 'pass', 'postal_code_check' => 'fail', 'cvc_check' => 'pass']
 *
 * Stripe pre-normalizes its response strings to the same vocabulary as our
 * {@see CheckResult} enum, so the mapping is a direct {@see CheckResult::from}.
 *
 * Each call site must request expansion of `payment_method` from Stripe API
 * (via `'expand' => ['payment_method']`) for these fields to be populated; on
 * non-expanded responses or when `card.checks` is absent, the result keys are
 * `null`, signaling "no signal" to the gateway transport layer.
 *
 * Returned shape — three keys are always present, values are nullable:
 * @phpstan-return array{address_line_check: ?string, postal_code_check: ?string, cvc_check: ?string}
 */
trait ExtractsCardChecks
{
    /**
     * @return array{address_line_check: ?string, postal_code_check: ?string, cvc_check: ?string}
     */
    private function extractStripeChecks(?PaymentMethod $paymentMethod): array
    {
        $checks = $paymentMethod?->card?->checks ?? null;

        return [
            'address_line_check' => self::normalize($checks?->address_line1_check ?? null),
            'postal_code_check' => self::normalize($checks?->address_postal_code_check ?? null),
            'cvc_check' => self::normalize($checks?->cvc_check ?? null),
        ];
    }

    private static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        return CheckResult::tryFrom($raw)?->value;
    }
}
