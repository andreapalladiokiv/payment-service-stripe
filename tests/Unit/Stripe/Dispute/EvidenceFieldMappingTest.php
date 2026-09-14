<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\ValueObject\DisputeEvidenceItem;
use Techork\PaymentService\Stripe\Dispute\EvidenceFieldMapping;
use Techork\PaymentService\Stripe\Dispute\Exception\EvidenceCannotBeFiled;
use Techork\PaymentService\Stripe\Dispute\Exception\EvidenceExceedsTextLimit;

/**
 * The one place this project's facts and Stripe's evidence fields are held together, and the one
 * place Stripe's 150,000-character ceiling is enforced. Nothing here touches the network: the
 * mapping composes a request body and refuses what cannot be sent, which is what makes "nothing is
 * uploaded before the block has been validated" a property of the object rather than a promise
 * about the order two calls happen to be made in.
 *
 * F7 says the limit must be validated before sending and must fail with a typed exception rather
 * than letting Stripe reject the call. Both halves are pinned: the boundary itself (150,000 taken,
 * 150,001 refused) and the type (an `InvalidArgumentException`, not a failed result a caller could
 * mistake for the provider's answer).
 */
function stripeDisputeEvidence(string $type, string $content, ?string $mediaType = null): DisputeEvidenceItem
{
    return new DisputeEvidenceItem($type, $content, $mediaType);
}

/** A base64 body, because a file's content crosses as encoded bytes and the uploader decodes it. */
function stripeDisputeFile(string $bytes = '%PDF-1.4 a delivery note'): string
{
    return base64_encode($bytes);
}

// ──────────────────────────────────────────────
//  composition
// ──────────────────────────────────────────────

/**
 * Four of our facts are "what our records show" and Stripe has one field for that. They are
 * composed, and each line names its fact: the value is prose a network analyst reads, and "Y, M,
 * 2025-11-04" says nothing without knowing which part is which.
 */
it('composes the facts that share a field into one labelled value, in the order they arrived', function () {
    $block = EvidenceFieldMapping::of('dp_1', [
        stripeDisputeEvidence('avs_cvv_result', 'Y, M'),
        stripeDisputeEvidence('authorization_timestamp', '2025-11-04T10:00:00Z'),
    ]);

    $line = "avs_cvv_result: Y, M\nauthorization_timestamp: 2025-11-04T10:00:00Z";

    expect($block->resolvedWith(static fn (): string => 'file_unused'))
        ->toBe(['access_activity_log' => $line])
        ->and($block->textCharacters())->toBe(mb_strlen($line))
        ->and($block->fileItems())->toBe([]);
});

/**
 * A document does not travel as text: it is uploaded and its field carries the id Stripe returned.
 * So the id appears only through `resolvedWith()`, which is why that is a callable rather than a
 * second mapping — the object cannot produce the id itself, and pretending otherwise would make a
 * `payload()` that describes a request nobody can send.
 */
it('resolves each document through the uploader and leaves the text fields alone', function () {
    $block = EvidenceFieldMapping::of('dp_1', [
        stripeDisputeEvidence('proof_of_delivery_or_service', stripeDisputeFile(), 'application/pdf'),
        stripeDisputeEvidence('statement_descriptor', 'ACME*SUBSCRIPTION'),
    ]);

    $uploaded = [];
    $evidence = $block->resolvedWith(function (DisputeEvidenceItem $item) use (&$uploaded): string {
        $uploaded[] = $item->type;

        return 'file_'.$item->type;
    });

    expect($evidence)->toBe([
        'uncategorized_text' => 'statement_descriptor: ACME*SUBSCRIPTION',
        'service_documentation' => 'file_proof_of_delivery_or_service',
    ])
        ->and($uploaded)->toBe(['proof_of_delivery_or_service'])
        ->and($block->fileItems())->toHaveCount(1);
});

/** The accepted terms are the one fact whose text and file fields differ — see the table. */
it('sends a fact whose text and file targets differ to the two different fields', function () {
    $block = EvidenceFieldMapping::of('dp_1', [
        stripeDisputeEvidence('terms_of_service_acceptance', stripeDisputeFile(), 'application/pdf'),
        stripeDisputeEvidence('terms_of_service_acceptance', 'accepted at checkout', null),
    ]);

    expect($block->resolvedWith(static fn (): string => 'file_terms'))->toBe([
        'uncategorized_text' => 'terms_of_service_acceptance: accepted at checkout',
        'uncategorized_file' => 'file_terms',
    ]);
});

// ──────────────────────────────────────────────
//  refusals, all of them before anything is sent
// ──────────────────────────────────────────────

it('refuses a fact it has no Stripe field for, naming the fact and the table', function () {
    expect(fn () => EvidenceFieldMapping::of('dp_1', [stripeDisputeEvidence('no_such_fact', 'x')]))
        ->toThrow(EvidenceCannotBeFiled::class, 'no_such_fact')
        ->toThrow(EvidenceCannotBeFiled::class, EvidenceFieldMapping::class);
});

/**
 * Some Stripe fields take prose only. A file id sent to one is a field Stripe cannot read, and the
 * base64 alternative would put a document's bytes into a statement the network reads as prose.
 */
it('refuses a document offered for a fact whose field takes text', function () {
    expect(fn () => EvidenceFieldMapping::of('dp_1', [
        stripeDisputeEvidence('buyer_ip_address', stripeDisputeFile(), 'application/pdf'),
    ]))->toThrow(EvidenceCannotBeFiled::class, 'buyer_ip_address');
});

/**
 * One field holds one value, so two documents cannot both be it. Stripe's own answer — the field
 * holding whichever arrived last — is a document silently dropped, on a case being lost.
 */
it('refuses two documents that would both be the single file id of one field', function () {
    expect(fn () => EvidenceFieldMapping::of('dp_1', [
        stripeDisputeEvidence('terms_of_service_acceptance', stripeDisputeFile(), 'application/pdf'),
        stripeDisputeEvidence('cancellation_confirmation', stripeDisputeFile(), 'image/jpeg'),
    ]))->toThrow(EvidenceCannotBeFiled::class, 'uncategorized_file');
});

// ──────────────────────────────────────────────
//  the 150,000-character ceiling
// ──────────────────────────────────────────────

/** `statement_descriptor` maps to the catch-all text field, whose line is prefixed by its name. */
function stripeDisputeTextOfLength(int $characters): DisputeEvidenceItem
{
    $prefix = strlen('statement_descriptor: ');

    return stripeDisputeEvidence('statement_descriptor', str_repeat('a', $characters - $prefix));
}

it('takes exactly the provider limit', function () {
    $block = EvidenceFieldMapping::of('dp_1', [stripeDisputeTextOfLength(150_000)]);

    expect($block->textCharacters())->toBe(150_000);
});

/**
 * Typed, and thrown where the block is composed. Stripe's own answer to an oversized submission is
 * a rejection of the whole call after the documents have been uploaded, which reads as a provider
 * fault and leaves an operator with no idea which part was too long.
 */
it('refuses one character more than the provider limit, naming the case and the count', function () {
    expect(fn () => EvidenceFieldMapping::of('dp_1', [stripeDisputeTextOfLength(150_001)]))
        ->toThrow(EvidenceExceedsTextLimit::class, 'dp_1')
        ->toThrow(EvidenceExceedsTextLimit::class, 'is 150001 characters of text')
        ->toThrow(EvidenceExceedsTextLimit::class, '150000');
});

/**
 * Files are not counted. They are uploaded and their fields carry an id, so counting an encoded PDF
 * towards a character budget would refuse submissions Stripe takes.
 */
it('does not count a document towards the text limit', function () {
    $block = EvidenceFieldMapping::of('dp_1', [
        stripeDisputeTextOfLength(150_000),
        stripeDisputeEvidence('proof_of_delivery_or_service', stripeDisputeFile(str_repeat('P', 400_000)), 'application/pdf'),
    ]);

    expect($block->textCharacters())->toBe(150_000)
        ->and($block->fileItems())->toHaveCount(1);
});

/**
 * The limit is over the whole submission, not over one field: two facts sharing a field are joined
 * with a separator, and that separator is text Stripe receives.
 */
it('counts a joined field with its separator towards the total', function () {
    $joined = "avs_cvv_result: Y, M\nauthorization_timestamp: 2025-11-04T10:00:00Z";

    $block = EvidenceFieldMapping::of('dp_1', [
        stripeDisputeEvidence('avs_cvv_result', 'Y, M'),
        stripeDisputeEvidence('authorization_timestamp', '2025-11-04T10:00:00Z'),
    ]);

    expect($block->textCharacters())->toBe(mb_strlen($joined));
});
