<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Concern;

use Techork\PaymentService\Gateway\Exception\IncompleteAuthentication;

/**
 * Maps an authentication result onto Stripe's `payment_method_options` block, or null when
 * the operation carries no authentication.
 *
 * Three request classes built this inline and identically, each sending explicit `null`s.
 * Stripe declares every member of the block as optional but NOT nullable
 * (`cryptogram?: string`, `version?: string`, `electronic_commerce_indicator?: string`), so
 * a null is not "no value" to it — it is a value of the wrong type. Absent means absent,
 * which is what the filter below produces.
 *
 * The cryptogram is refused rather than omitted. Unlike the ConnexPay guard this one is not
 * measured — Stripe's behaviour on a cryptogram-less block has not been probed — so it
 * rests on two things that hold whoever the acquirer is. The domain already treats a
 * success status carrying no authentication value as incoherent rather than as a refusal
 * ({@see \Techork\PaymentService\Domain\PaymentIntent\MissingChallengeEvidenceExtractor}),
 * and ConnexPay was measured to accept exactly that shape and process it as UNAUTHENTICATED
 * while reporting nothing back
 * ({@see \Techork\PaymentService\ConnexPay\Concern\FormatsThreeDS}). Refusing keeps the two
 * providers answering the same input the same way, instead of one throwing and the other
 * quietly shipping an attestation the issuer never applied.
 *
 * {@see IncompleteAuthentication} carries the
 * {@see \Techork\PaymentService\Gateway\Exception\UnsupportedByGateway} marker, so the
 * router rethrows it rather than folding a structural refusal into an acquirer decline.
 */
trait FormatsThreeDS
{
    /**
     * @return array{card: array{three_d_secure: array<string, string>}}|null
     */
    protected function formatThreeDS(): ?array
    {
        $threeDS = $this->getThreeDS();

        if ($threeDS === null) {
            return null;
        }

        if (($threeDS->authenticationValue ?? '') === '') {
            throw IncompleteAuthentication::missingFields(
                'stripe',
                lcfirst((string) preg_replace('/Request$/', '', basename(str_replace('\\', '/', static::class)))),
                ['cryptogram'],
            );
        }

        return [
            'card' => [
                'three_d_secure' => array_filter([
                    'cryptogram' => $threeDS->authenticationValue,
                    'transaction_id' => $threeDS->dsTransactionId,
                    'ares_trans_status' => $threeDS->status->value,
                    'version' => $threeDS->version?->value,
                    'electronic_commerce_indicator' => $threeDS->eci?->value,
                ], static fn (?string $value): bool => $value !== null),
            ],
        ];
    }
}
