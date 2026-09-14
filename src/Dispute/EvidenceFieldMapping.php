<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Dispute;

use InvalidArgumentException;
use Techork\PaymentService\Gateway\ValueObject\DisputeEvidenceItem;
use Techork\PaymentService\Stripe\Dispute\Exception\EvidenceCannotBeFiled;
use Techork\PaymentService\Stripe\Dispute\Exception\EvidenceExceedsTextLimit;

/**
 * Our facts, written into Stripe's evidence block — the provider-code mapping F7 asks for.
 *
 * ## The two vocabularies
 *
 * The domain names the facts a network counts (`avs_cvv_result`, `proof_of_delivery_or_service`)
 * because a requirement table has to be stated once, before either submission adapter exists.
 * Stripe names the fields they go in, and the names do not correspond: it has one free-text log
 * field, one catch-all text field, one catch-all file field, and a shipping/refund/billing family
 * this project's vocabulary does not reach into at all. This class is the one place the two are
 * held together, and it is the only place either name is written down — an adapter that carried
 * its own copy would state the mapping twice, and the copies would drift without anything failing.
 *
 * ## This mapping is our reading, not the Provider Reference's
 *
 * The plan's Stripe section fixes endpoints, statuses and the 150,000-character limit; it states
 * no evidence field for any fact. The assignments below are traceable to fields the Stripe API
 * documents, and the choice of field per fact is this project's reading of what each one puts in
 * issue. It is the same standing item F6 wrote over its requirement table: **re-check these against
 * Stripe's dispute-categories guide before this ships**, and leave a fact absent rather than
 * inventing a field for it. The pairs a reader should question hardest are the loose ones —
 * the statement descriptor and the accepted terms, which Stripe has no field for and which go to
 * the catch-all text field.
 *
 * ## Several facts in one text field, and never two documents in one
 *
 * Stripe's text fields are prose, and more than one of our facts belongs in the same one: the AVS
 * and CVV results, the 3DS outcome, when the authorization happened and the card's history with us
 * are all "what the log shows", which is what `access_activity_log` is for. They are composed into
 * one value — each line labelled with the fact it states, because a free-text field read by a
 * network analyst has to say which fact is which — in the order the package carried them.
 *
 * Files are the other way round, and the asymmetry is Stripe's: a text field has room for a page of
 * prose, while a field that takes a file id takes exactly one. Two documents mapped to one field is
 * therefore refused ({@see EvidenceCannotBeFiled::twoFilesForOneField()}) rather than resolved by
 * sending one — Stripe keeps whichever arrived last and drops the other without saying so.
 *
 * ## The 150,000-character limit is enforced here, before anything is sent
 *
 * It is a total across the text fields of one submission, and it is counted on the values this
 * class has just composed — which is what Stripe counts, labels and all. It is checked at
 * composition rather than at the call because a submission that is too long must be refused
 * *before* the files are uploaded: the uploads are separate calls that have already happened by
 * then, and a rejection after them leaves documents orphaned on the account and an operator with no
 * idea which part was too long.
 */
final readonly class EvidenceFieldMapping
{
    /**
     * How much text Stripe takes in one submission, across every text evidence field.
     *
     * From the plan's Stripe section: "Text evidence limit: 150,000 characters in total." Files are
     * not counted: they are uploaded and their fields carry an id.
     */
    public const int TEXT_CHARACTER_LIMIT = 150_000;

    /**
     * The table: our fact, the field its text goes in, and the field its file goes in.
     *
     * A null file target means Stripe reads that field as text only, so a document offered for the
     * fact is refused rather than sent ({@see EvidenceCannotBeFiled::noFileTarget()}). A file target
     * equal to the text target is the ordinary case: Stripe's `service_documentation`,
     * `customer_communication` and `cancellation_policy` each take prose or a file id.
     *
     * @var array<string, array{text: string, file: ?string}>
     */
    private const array FIELDS = [
        // The system's own facts, all of them "what our records show". Stripe has one field for
        // that, and four of them land in it; see the class docblock for why they are composed
        // rather than forced into fields of their own that would each claim something else.
        'avs_cvv_result' => ['text' => 'access_activity_log', 'file' => null],
        'three_ds_status_and_liability_shift' => ['text' => 'access_activity_log', 'file' => null],
        'authorization_timestamp' => ['text' => 'access_activity_log', 'file' => null],
        'payment_card_history' => ['text' => 'access_activity_log', 'file' => null],

        // Stripe's field for where the buyer was at checkout, which is exactly this fact.
        'buyer_ip_address' => ['text' => 'customer_purchase_ip', 'file' => null],

        // No Stripe field says "the descriptor the cardholder saw". The catch-all text field is
        // where a merchant explains it, and it is the loosest pair in this table.
        'statement_descriptor' => ['text' => 'uncategorized_text', 'file' => null],

        // Stripe's own name for this one, and it takes the delivery note or the signed receipt as
        // a file or as prose describing the service rendered.
        'proof_of_delivery_or_service' => ['text' => 'service_documentation', 'file' => 'service_documentation'],

        // The exchange with the buyer: Stripe takes the thread as text or the email as a file.
        'customer_correspondence' => ['text' => 'customer_communication', 'file' => 'customer_communication'],

        // No Stripe field for accepted terms either. The text goes to the catch-all text field
        // beside the descriptor; a PDF of the terms goes to the catch-all FILE field, which is why
        // this entry is the only one whose two targets differ.
        'terms_of_service_acceptance' => ['text' => 'uncategorized_text', 'file' => 'uncategorized_file'],

        // Stripe's field is literally this policy, and it takes the policy itself.
        'cancellation_policy' => ['text' => 'cancellation_policy', 'file' => 'cancellation_policy'],

        // The record that the buyer cancelled or that we acknowledged it. Stripe's
        // `cancellation_rebuttal` is where a merchant answers a cancellation claim, which is what
        // this fact is doing; an email copy of it goes to the catch-all file field.
        'cancellation_confirmation' => ['text' => 'cancellation_rebuttal', 'file' => 'uncategorized_file'],
    ];

    /** @var array<string, string> Stripe field => the composed text that goes in it */
    private array $texts;

    /** @var array<string, DisputeEvidenceItem> Stripe field => the one document that goes in it */
    private array $files;

    /**
     * @param  array<string, string>  $texts
     * @param  array<string, DisputeEvidenceItem>  $files
     */
    private function __construct(
        private string $disputeReference,
        private int $textCharacters,
        array $texts,
        array $files,
    ) {
        $this->texts = $texts;
        $this->files = $files;
    }

    /**
     * The evidence block these items amount to, or a typed refusal naming what cannot be sent.
     *
     * @param  array<array-key, DisputeEvidenceItem>  $items  the facts to file, with a file item's
     *   content still the encoded bytes — resolving it to the id Stripe returned is
     *   {@see self::resolvedWith()}'s job, and it happens after everything here has passed
     *
     * @throws EvidenceCannotBeFiled when an item has no field, or two documents claim one field
     * @throws EvidenceExceedsTextLimit when the composed text is over the provider's limit
     */
    public static function of(string $disputeReference, array $items): self
    {
        $texts = [];
        $files = [];
        $characters = 0;

        foreach ($items as $item) {
            $item instanceof DisputeEvidenceItem || throw new InvalidArgumentException(
                'An evidence block is composed from dispute evidence items; '
                . get_debug_type($item).' arrived in the list.',
            );

            $field = self::fieldFor($item);

            if (! $item->isFile()) {
                $line = self::labelled($item);

                // Counted as it is composed, so the figure is the character count of the values
                // Stripe will receive — including the separator a joined field gained.
                if (isset($texts[$field])) {
                    $texts[$field] .= "\n".$line;
                    $characters += mb_strlen($line) + 1;
                } else {
                    $texts[$field] = $line;
                    $characters += mb_strlen($line);
                }

                continue;
            }

            $existing = $files[$field] ?? null;
            $existing === null || throw EvidenceCannotBeFiled::twoFilesForOneField(
                $field,
                $existing->type,
                $item->type,
            );

            $files[$field] = $item;
        }

        $characters <= self::TEXT_CHARACTER_LIMIT || throw EvidenceExceedsTextLimit::forDispute(
            $disputeReference,
            $characters,
            self::TEXT_CHARACTER_LIMIT,
        );

        return new self($disputeReference, $characters, $texts, $files);
    }

    /**
     * How much text this submission carries, counted the way Stripe counts it.
     *
     * Public because a caller that is deciding whether to send — the application assembling an
     * operator's screen — may want the figure before the call, without composing a second time.
     */
    public function textCharacters(): int
    {
        return $this->textCharacters;
    }

    /**
     * The documents that have to be uploaded before this block can be sent.
     *
     * @return list<DisputeEvidenceItem>
     */
    public function fileItems(): array
    {
        return array_values($this->files);
    }

    /**
     * The `evidence` object Stripe takes, once the documents in it have ids.
     *
     * The uploader is a callable rather than a dependency so that this stays a mapping: it does not
     * know the File Upload API exists, only that a document turns into the string its field carries
     * — and it is called here, per document, in this one place, which is what makes "nothing is
     * uploaded before the block has been validated" a property of the code rather than a promise.
     *
     * @param  callable(DisputeEvidenceItem): string  $upload  returns the provider's id for a
     *   document it has just uploaded
     *
     * @return array<string, string>
     */
    public function resolvedWith(callable $upload): array
    {
        $evidence = $this->texts;

        foreach ($this->files as $field => $item) {
            $evidence[$field] = $upload($item);
        }

        return $evidence;
    }

    /**
     * Which Stripe field answers this fact, in the form the fact travels in.
     *
     * Text and file are asked separately because Stripe's fields do not all take both, and the
     * refusal has to name the fact rather than the field: it is the caller's vocabulary that is
     * missing a way to send it.
     */
    private static function fieldFor(DisputeEvidenceItem $item): string
    {
        $entry = self::FIELDS[$item->type] ?? throw EvidenceCannotBeFiled::unmappedType($item->type);

        if (! $item->isFile()) {
            return $entry['text'];
        }

        return $entry['file'] ?? throw EvidenceCannotBeFiled::noFileTarget($item->type);
    }

    /**
     * One fact's line in a shared text field.
     *
     * Every line names its fact. The field it lands in is prose that a network analyst and a
     * reviewer in Stripe's dashboard both read, and "Y, M, 2025-11-04, no prior disputes" says
     * nothing without knowing which fact each part is — particularly where four of ours share one
     * field.
     */
    private static function labelled(DisputeEvidenceItem $item): string
    {
        return $item->type.': '.$item->content;
    }
}
