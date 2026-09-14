<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Dispute\Exception;

use InvalidArgumentException;
use Techork\PaymentService\Stripe\Dispute\EvidenceFieldMapping;

/**
 * The evidence cannot be put into Stripe's request at all, and no call would change that.
 *
 * Three ways, all of them the mapping's business rather than Stripe's: a fact with no evidence
 * field to go in, a document offered for a fact whose field takes text, and two documents that
 * would both have to be the single file id of one field. Each is refused before the network is
 * touched, because Stripe's answer to the alternative is either a dropped field (a document nobody
 * ever sees, on a case being lost by default) or a decode error about a part it never received.
 *
 * Extends `InvalidArgumentException` rather than folding into a failed `GatewayResult`, for the
 * reason {@see EvidenceExceedsTextLimit} does: the provider said nothing, and a caller that
 * recorded this as "Stripe would not take the evidence" would be recording a refusal that never
 * happened. It carries no `ErrorCode` for the same reason it is not a result — see that class.
 */
final class EvidenceCannotBeFiled extends InvalidArgumentException
{
    /**
     * A fact this project names that Stripe has no field for.
     *
     * Reachable only from a mismatch between this table and the domain's vocabulary — the fact
     * names are a closed enum on the other side of the boundary — which is why the message names
     * the fact and the table rather than talking about the case.
     */
    public static function unmappedType(string $type): self
    {
        return new self(sprintf(
            'Evidence of type "%s" has no Stripe evidence field mapped to it. Add the pair to '
            . '%s rather than dropping the fact: a submission sent without it argues the case '
            . 'with a gap nobody can see.',
            $type,
            EvidenceFieldMapping::class,
        ));
    }

    /**
     * A document offered for a fact whose Stripe field takes text.
     *
     * Stripe's evidence block mixes the two: some fields take a string of prose OR a file id, and
     * some take prose only. Sending a file id where only text is read leaves Stripe with a field
     * it cannot display — and sending the base64 instead would put a document's bytes in a
     * statement the network reads as prose.
     */
    public static function noFileTarget(string $type): self
    {
        return new self(sprintf(
            'Evidence of type "%s" is a file, and its Stripe evidence field takes text only. '
            . 'Supply the fact as text, or send the document as a fact whose field accepts one — '
            . 'an uploaded file id in a text field is a field Stripe cannot read.',
            $type,
        ));
    }

    /**
     * Two documents for one field.
     *
     * A Stripe evidence field holds one value, so two file ids cannot both be it — and Stripe's
     * own answer, a field holding whichever arrived last, is a document silently dropped. Which
     * one answers the network's question is a decision the collection stage owes, so it is refused
     * here rather than resolved by sending one of them.
     */
    public static function twoFilesForOneField(string $field, string $first, string $second): self
    {
        return new self(sprintf(
            'Evidence of types "%s" and "%s" are both files and both map to Stripe\'s "%s" field, '
            . 'which holds one value. Send one of them, or send the other as a fact with its own '
            . 'field: Stripe would keep whichever arrived last and drop the other without saying so.',
            $first,
            $second,
            $field,
        ));
    }

    /**
     * Bytes that are not base64.
     *
     * The domain's item refuses this at construction, so reaching here means the content crossed
     * the boundary by some other route — and the failure it prevents is the one that actually
     * happens: raw bytes uploaded as a corrupt part, which Stripe answers with a decode error that
     * reads like a provider fault rather than like ours.
     */
    public static function undecodable(string $type): self
    {
        return new self(sprintf(
            'Evidence of type "%s" is a file whose content is not base64. Files cross into the '
            . 'provider as encoded bytes; raw bytes would be uploaded as a corrupt part and read '
            . 'as a provider fault.',
            $type,
        ));
    }
}
