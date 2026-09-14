<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Dispute\Exception;

use InvalidArgumentException;

/**
 * Stripe counts 150,000 characters of text evidence in one submission, and this is that ceiling.
 *
 * F7 asks for it to be validated here rather than left to the API: Stripe rejects the whole call
 * with a message about the evidence object, which reads as a provider fault and arrives after the
 * documents have been uploaded — and the operator who assembled them has no way to tell what was
 * too long. The count is over the text fields only. A file does not travel as text at all; it is
 * uploaded and the field carries `file_…`, so counting an encoded PDF towards a character budget
 * would refuse submissions that Stripe takes.
 *
 * ## Typed, and deliberately not a failed `GatewayResult`
 *
 * This is not something the provider said — it is a request we can see is unacceptable before it is
 * sent — so it must not be folded into the same shape as a provider refusal, which is the one the
 * port above turns into "the provider would not take the evidence". It extends
 * `InvalidArgumentException` for that reason, and the layers in between let it through: nothing
 * between this driver and the port catches a `Throwable` on this path, which the adapter's test
 * pins. Should the dispute stack ever be wrapped in a failure boundary the way the acquiring roles
 * are, that boundary has to name this exception as one it rethrows, or a caller's typo becomes an
 * operator's "Stripe refuses our evidence".
 *
 * It carries no `ErrorCode`. The codes in `Common` are payment-shaped (an amount, a currency, an
 * authentication result) and the dispute domain has no refusal vocabulary of its own yet — a code
 * invented here would be one nobody could catch by.
 */
final class EvidenceExceedsTextLimit extends InvalidArgumentException
{
    public static function forDispute(string $disputeReference, int $characters, int $limit): self
    {
        return new self(sprintf(
            'The evidence assembled for dispute "%s" is %d characters of text, and Stripe accepts '
            . '%d across every text evidence field in one submission. Shorten it or split the '
            . 'response before sending: Stripe rejects the call outright rather than trimming it, '
            . 'and sending it would have uploaded the files for nothing.',
            $disputeReference,
            $characters,
            $limit,
        ));
    }
}
