<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Dispute;

/**
 * Stripe's seven dispute statuses, and the stage and status each one stands in.
 *
 * ## Why the table lives here
 *
 * Because the alternative does not compile: `GatewayDisputeRecorder` is handed a `GatewayId` and
 * nothing else, and `src/Laravel/composer.json` requires no provider package, so the recorder that
 * reads a snapshot cannot see this class — nor ConnexPay's. A recorder handed Stripe's raw
 * `warning_needs_response` would either invent a stage or refuse every inquiry-phase case, and
 * `'warning_*'` is not a stage in any vocabulary. So the package that owns the provider's words owns
 * the sentence that turns them into ours, which is the same argument
 * {@see \Techork\PaymentService\ConnexPay\Dispute\CaseMapping} makes for itself and the same one
 * {@see \Techork\PaymentService\Stripe\Webhook\DisputePayload}'s docblock used to refuse.
 *
 * **What this class does not do is state a `DisputeStage`.** It answers the domain's own *spelling*
 * of one — `'inquiry'`, which is `DisputeStage::Inquiry->value` — and nothing more, exactly as
 * `CaseMapping::stage()` does. Declaring the enum here would be a second copy of a vocabulary
 * `Domain` owns, which §0.4 forbids; a string is not a vocabulary.
 *
 * ## The table, verbatim from §F3
 *
 * | Stripe `status` | Stage | Status |
 * |---|---|---|
 * | `warning_needs_response` | `inquiry` | `needs_response` |
 * | `warning_under_review` | `inquiry` | `under_review` |
 * | `warning_closed` | `inquiry` | `closed` |
 * | `needs_response` | `chargeback` | `needs_response` |
 * | `under_review` | `chargeback` | `under_review` |
 * | `won` | `chargeback` | `won` |
 * | `lost` | `chargeback` | `lost` |
 *
 * The `warning_` prefix is the whole of the stage rule and it is Stripe's own: a warning is an
 * inquiry the issuer opened before escalating, which is why `warning_closed` is a case that sat out
 * its 120 days rather than one anybody decided — and why {@see
 * \Techork\PaymentService\Stripe\Webhook\DisputePayload::isOutcome()} sends only `won` and `lost`
 * through the resolution call. The four unprefixed statuses are the chargeback phase, and each maps
 * to itself.
 *
 * ## Traps, and where they are defended
 *
 * - `PRE_ARBITRATION` and `ARBITRATION` are **never** Stripe stages: it does not support the
 *   arbitration phase at all. Nothing here can return them, and nothing here should grow a branch
 *   that appears to.
 * - `warning_closed` must not be read as a decision. It is `closed`, which `DisputeStatus::allows()`
 *   accepts as a status and which is deliberately not `won` or `lost`.
 * - **A status this table does not cover answers `null`, and null is not a stage.** Stripe adds
 *   statuses; the caller must refuse the delivery loudly rather than let a case be filed under a
 *   guessed stage, which is what {@see \Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot}
 *   documents as the meaning of a code with no spelling beside it. `null` here is a statement about
 *   this table, not about the case.
 */
final class StatusMapping
{
    /**
     * Stripe's status onto the two domain spellings it stands in — one table, so the halves cannot
     * drift apart: a status whose stage says inquiry and whose status says `won` is not a shape
     * Stripe can report, and splitting this into two tables would make that shape writable.
     *
     * @var array<string, array{stage: string, status: string}>
     */
    private const array BY_STATUS = [
        'warning_needs_response' => ['stage' => 'inquiry', 'status' => 'needs_response'],
        'warning_under_review' => ['stage' => 'inquiry', 'status' => 'under_review'],
        'warning_closed' => ['stage' => 'inquiry', 'status' => 'closed'],
        'needs_response' => ['stage' => 'chargeback', 'status' => 'needs_response'],
        'under_review' => ['stage' => 'chargeback', 'status' => 'under_review'],
        'won' => ['stage' => 'chargeback', 'status' => 'won'],
        'lost' => ['stage' => 'chargeback', 'status' => 'lost'],
    ];

    /**
     * The stage Stripe's status places the case in, or null when the table does not cover it.
     */
    public static function stage(string $status): ?string
    {
        return self::BY_STATUS[$status]['stage'] ?? null;
    }

    /**
     * The status Stripe's own word means, in the domain's spelling, or null when it is not covered.
     */
    public static function status(string $status): ?string
    {
        return self::BY_STATUS[$status]['status'] ?? null;
    }
}
