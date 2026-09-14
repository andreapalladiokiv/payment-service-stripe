<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Dispute;

use DateTimeImmutable;
use RuntimeException;
use Stripe\Dispute;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Gateway\Command\DisputeCaseQuery;
use Techork\PaymentService\Gateway\Contract\DisputeCaseReading;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * `GET /v1/disputes/:id` — what the provider says is still open on a case.
 *
 * ## Why this exists at all, when the events already describe the case
 *
 * The deliveries that open and move a case arrive on their own schedule, and the window between
 * one arriving and the next is when a case is lost by default. An action set assembled from the
 * last delivery we happened to receive would describe the case as of that delivery, and offer a
 * response on a case the provider has since decided. So the two facts an operator acts on are
 * asked of the provider at the moment they are shown, and this read is that question.
 *
 * It is the only operation in this domain that changes nothing, and it is also the only one whose
 * failure has no result to carry it: the role answers with a reading rather than a
 * {@see \Techork\PaymentService\Gateway\Contract\GatewayResult}, so an API error is thrown rather
 * than handed back. That is the honest shape — a caller that could not find out what a case is
 * waiting for has not learned that it is waiting for nothing, and a caller that treated an empty
 * answer as "nothing to do" would be reporting a case as closed because a request timed out.
 *
 * ## What the provider's vocabulary is read into
 *
 * The statuses are Stripe's and stay here: `needs_response` and its `warning_` sibling are the two
 * that mean the case is waiting for us, and every other one — `under_review`, `won`, `lost`,
 * `prevented`, `warning_closed` — means it is with the network or decided. `warning_closed` is the
 * one worth naming: the plan reads it as an inquiry that sat 120 days without escalating, so it is
 * an expiry rather than a case somebody decided.
 *
 * `concedable` is narrowed further, to a case whose `case_type` is `chargeback`. `close` is
 * documented as `needs_response → lost`, and an inquiry is a different kind of case: the plan maps
 * its closed status to `CLOSED`/expired rather than to a concession, so a reading that offered the
 * concession on one would offer an irreversible call whose own answer we could not read as ours.
 * Withholding it costs an operator a portal visit; offering it wrongly costs the disputed sum.
 *
 * ## The pair, and the deadline that is not optional on a case we must answer
 *
 * `brand` and `network_reason_code` travel together because they are one key — the evidence
 * template is looked up by the pair, and either half alone answers no question. Both are nullable:
 * a payload that does not state them leaves a case that must be surfaced without a template rather
 * than one that is guessed at.
 *
 * `evidence_details.due_by` is Stripe's own deadline and the only thing an operator can be given a
 * task with. It is read whatever the status; when the case is waiting for us and the payload omits
 * it, {@see DisputeCaseReading}'s own invariant refuses the reading rather than handing up a case
 * that reads as having nothing open — which is exactly the state a case about to be lost by default
 * would be in.
 *
 * Every nested read goes through `?? null` and a type guard, for the reason
 * {@see \Techork\PaymentService\Stripe\Concern\ExtractsConvertedAmount} does it: a bare property
 * read on a Stripe object reaches `StripeObject::__get()`, which logs a notice before answering
 * null, and a payload that omits `payment_method_details` is an ordinary shape here.
 */
final readonly class DisputeCaseRead
{
    /**
     * The statuses that mean the case is still ours to answer. Both forms exist because an inquiry
     * carries the `warning_` prefix — the stage is derived from it on the ingestion side, and the
     * question asked here is the same for either.
     *
     * @var list<string>
     */
    private const array AWAITING_RESPONSE = [
        Dispute::STATUS_NEEDS_RESPONSE,
        Dispute::STATUS_WARNING_NEEDS_RESPONSE,
    ];

    /**
     * The case type `close` is documented for: a chargeback, which is the case that can be lost.
     * An inquiry is not challenged the same way and its closed status is an expiry — see the class
     * docblock.
     */
    private const string CONCEDABLE_CASE_TYPE = 'chargeback';

    public function __construct(
        private StripeSettings $settings,
        private DisputeCaseQuery $query,
    ) {}

    /**
     * @throws RuntimeException when Stripe could not be asked — the previous exception carries
     *   Stripe's own message, which names the case and the problem
     */
    public function read(): DisputeCaseReading
    {
        try {
            $dispute = (new StripeClient($this->settings->apiKey))->disputes->retrieve(
                $this->query->disputeReference,
            );
        } catch (ApiErrorException $e) {
            throw new RuntimeException(sprintf(
                'Stripe could not be asked about dispute "%s", so what it is still waiting for is '
                .'unknown. A read that failed is not a case with nothing open on it. Stripe said: %s',
                $this->query->disputeReference,
                $e->getMessage(),
            ), previous: $e);
        }

        $status = $dispute->status ?? null;

        $card = $dispute->payment_method_details?->card ?? null;
        $reasonCode = $card?->network_reason_code ?? null;

        return new DisputeCaseReading(
            awaitingResponse: is_string($status) && in_array($status, self::AWAITING_RESPONSE, true),
            concedable: in_array($status, self::AWAITING_RESPONSE, true)
                && ($card?->case_type ?? null) === self::CONCEDABLE_CASE_TYPE,
            cardBrand: $card?->brand ?? null,
            reasonCode: is_string($reasonCode) ? $reasonCode : null,
            respondBy: self::deadline($dispute),
        );
    }

    /**
     * Stripe's response deadline as an instant, or null when the payload states none.
     *
     * Unix seconds, converted the way the ingestion side converts the same field — the two must
     * read one payload into one date, or an operator's task and the deadline the case records would
     * disagree about when it is due.
     */
    private static function deadline(Dispute $dispute): ?DateTimeImmutable
    {
        $dueBy = $dispute->evidence_details?->due_by ?? null;

        return is_int($dueBy) ? new DateTimeImmutable('@'.$dueBy) : null;
    }
}
