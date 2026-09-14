<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Stripe\Dispute\StatusMapping;

/**
 * The table §F3 states, pinned row by row.
 *
 * This is the only place Stripe's seven dispute statuses become the two axes the aggregate is
 * driven on, and all seven arrive as a raw string out of a webhook payload — so a wrong row is not
 * a wrong answer in some read model, it is a case filed on the wrong phase of the chargeback cycle
 * or decided when nobody decided it. The rows are therefore written out here rather than sampled,
 * the way `ConnexPay\Dispute\CaseMapping`'s are, so that changing the reading is a visible change
 * to a test rather than a silent change to how a dispute is filed.
 *
 * ## What is asserted, and what is deliberately not
 *
 * The two halves are asserted **together**, one row at a time, because they are one table: a status
 * whose stage says `inquiry` and whose status says `won` is not a shape Stripe reports, and two
 * separate tables would make that shape writable. Two further assertions are table-wide and neither
 * restates a row: every value answered is a real case of the enum it will be read as — which is
 * what the recorder's `tryFrom` depends on — and no row reaches a phase Stripe does not have.
 *
 * Nothing here asserts that Stripe's statuses behave as described. That reading is what is being
 * pinned; where a value is inferred rather than quoted, {@see StatusMapping}'s docblock says so,
 * and the rows below quote §F3.
 */
$stripeDisputeStatuses = [
    'a warning is an inquiry before it escalates' => ['warning_needs_response'],
    'a warning the network has taken up' => ['warning_under_review'],
    'a warning that ran out of time' => ['warning_closed'],
    'the chargeback phase is the unprefixed four' => ['needs_response'],
    'a chargeback under review' => ['under_review'],
    'a chargeback the issuer decided for us' => ['won'],
    'a chargeback the issuer decided against us' => ['lost'],
];

// ──────────────────────────────────────────────
//  the table
// ──────────────────────────────────────────────

it('reads each status onto the stage and the status §F3 names for it', function (string $status, string $stage, string $stated) {
    expect(StatusMapping::stage($status))->toBe($stage)
        ->and(StatusMapping::status($status))->toBe($stated);
})->with([
    // §F3's table verbatim — left column Stripe's `status`, right two the domain's spellings.
    'warning_needs_response' => ['warning_needs_response', 'inquiry', 'needs_response'],
    'warning_under_review' => ['warning_under_review', 'inquiry', 'under_review'],
    'warning_closed' => ['warning_closed', 'inquiry', 'closed'],
    'needs_response' => ['needs_response', 'chargeback', 'needs_response'],
    'under_review' => ['under_review', 'chargeback', 'under_review'],
    'won' => ['won', 'chargeback', 'won'],
    'lost' => ['lost', 'chargeback', 'lost'],
]);

// ──────────────────────────────────────────────
//  the two traps the prefix rule creates
// ──────────────────────────────────────────────

it('reads no warning_ status as a decision, because a closed inquiry was not decided', function (string $status) {
    // `DisputePayload::isOutcome()` sends only `won` and `lost` down the resolution path, and the
    // two halves of that have to agree: a warning that answered `isResolution()` here would put a
    // case nobody judged into a terminal status through `onDisputeResolved()`, which is the one
    // outcome that is final. `warning_closed` is the row this is really about — it is the status
    // that looks most like an ending and is the one that ends nothing.
    expect(DisputeStatus::tryFrom((string) StatusMapping::status($status))?->isResolution())->toBeFalse();
})->with([
    'warning_needs_response' => ['warning_needs_response'],
    'warning_under_review' => ['warning_under_review'],
    'warning_closed' => ['warning_closed'],
]);

it('reads warning_closed as closed and not as expired, which is a different fact', function () {
    // `DisputeStatus` reaches both `closed` and `expired` from `needs_response`, so the enum does
    // not settle this one and the table has to. `expired` is defined there as a signal that a
    // response *window* we may declare closed has run out — our own clock — while this is Stripe
    // saying the inquiry is over. §F3 names `closed`, and the distinction is pinned so that a later
    // reading of the Stripe docs cannot quietly swap one for the other.
    expect(StatusMapping::status('warning_closed'))->toBe('closed');
});

// ──────────────────────────────────────────────
//  table-wide, for a row not yet written
// ──────────────────────────────────────────────

it('answers only spellings the enums it will be read as actually have', function (string $status) {
    // The recorder resolves both values with `DisputeStage::tryFrom` / `DisputeStatus::tryFrom` and
    // refuses one it cannot place loudly — so a typo in the table is not a wrong case, it is every
    // delivery of that status failing, with no case filed at all. These are the same two calls the
    // recorder makes, on the same values, which is what makes this a test of the contract rather
    // than of the table's spelling.
    expect(DisputeStage::tryFrom((string) StatusMapping::stage($status)))->not->toBeNull()
        ->and(DisputeStatus::tryFrom((string) StatusMapping::status($status)))->not->toBeNull();
})->with($stripeDisputeStatuses);

it('places a case in one of the two phases Stripe has, and never in an arbitration phase', function (string $status) {
    // Stripe's dispute cycle has an inquiry phase and a chargeback phase and no arbitration phase
    // at all: `pre_arbitration` and `arbitration` are ConnexPay's, and Stripe offers no route into
    // them. A row answering either one would be this table claiming Stripe supports something its
    // API does not, which is a claim no delivery could ever confirm.
    expect(StatusMapping::stage($status))->toBeIn(['inquiry', 'chargeback']);
})->with($stripeDisputeStatuses);

// ──────────────────────────────────────────────
//  a status this table does not cover
// ──────────────────────────────────────────────

it('gives no answer at all for a status it does not cover', function (string $status) {
    // Null is a statement about this table, not about the case. Stripe adds statuses, and the
    // callers' job — `DisputePayload` filling `$stage` / `$status`, the recorder refusing a code
    // with no spelling beside it — is to fail the delivery loudly rather than let a case be filed
    // under a guessed stage. A default here would be that guess.
    expect(StatusMapping::stage($status))->toBeNull()
        ->and(StatusMapping::status($status))->toBeNull();
})->with([
    // The shape a new status arrives in: the same prefix, a word this table has no row for.
    'a warning status this table does not cover' => ['warning_reversed'],
    // A payload whose status is missing reaches here as an empty string, and answers the same
    // nothing — which the recorder then reads as the provider stating no position, not as a case
    // with none.
    'the empty string' => [''],
    // Stripe's statuses are lowercase; the uppercase spelling is a different string and must not
    // be quietly folded onto the same row.
    'a case difference is a different word' => ['WON'],
    // The phase names this project owns, offered where a status is expected.
    'a stage name rather than a status' => ['chargeback'],
    'a status name this project owns' => ['needs_attention'],
]);
