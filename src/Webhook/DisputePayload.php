<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook;

use DateTimeImmutable;
use InvalidArgumentException;
use Money\Currency;
use Money\Money;
use Stripe\Event;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeResolution;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Stripe\Dispute\StatusMapping;

/**
 * One Stripe dispute delivery, read the way the three dispute handlers need it.
 *
 * ## Why this is a class and not three copies of the same six reads
 *
 * `charge.dispute.created` and `charge.dispute.updated` carry the *same* object — a `dispute` —
 * and report it the same way, so their handlers are the same handler with a different event type
 * and the field reads would otherwise be written out three times. The reads are also the only
 * place this package touches Stripe's dispute vocabulary, and Stripe's vocabulary is the thing
 * that changes: `payment_method_details` was added to the object years after `reason` and
 * `status`, and a field list copied into three files is a field list that gets fixed in two of
 * them. One reader means one place to look when Stripe moves.
 *
 * ## The event key is Stripe's own `event.id`, and that is not a convenience
 *
 * {@see \Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal} carries one component —
 * the provider's own key for the delivery — and names Stripe's value for it outright: "Stripe: the
 * webhook event.id". The gateway is deliberately not part of it: a case lives at one gateway account
 * for its whole life, so within one stream it could never tell two keys apart, and the recorder that
 * receives this value is handed the gateway as an argument anyway — `DisputeSignal` sets out the
 * whole of that. Nor does it carry a third: the case's identity is the dispute id we mint, and the
 * provider's reference for the case is not an identity at all — it lives in `gateway_references`,
 * where the mapping between it and the id is the only thing that belongs. Three properties make this
 * key the right one, and they are worth stating, because {@see DisputeSnapshot} refuses an empty key
 * precisely because a key that is not per-delivery silently drops facts:
 *
 *  - **it is per delivery.** Stripe mints a new `evt_…` for every event it publishes, so two
 *    genuinely different facts about one case — an evidence deadline that moved and a status that
 *    became `under_review` — never share a key, and neither is suppressed as a repeat of the
 *    other;
 *  - **it is stable across retries.** A retry re-sends the *same* event object, id included, so
 *    the redelivery is recognised rather than applied twice. That is the property a derived key
 *    (a hash of the payload, say) would destroy: Stripe's own retry would then look like a new
 *    fact whenever any field moved;
 *  - **it is already the idempotency key one layer out.** {@see EventParser} publishes the same
 *    value as `ParsedEvent::externalId`, which is what the machinery stores and deduplicates on.
 *    Using it here means the stored row and the aggregate's own trail agree about what "this
 *    delivery" means, rather than being two spellings of it.
 *
 * Nothing here parses it: the aggregate compares it for equality and never reads anything out of
 * it.
 *
 * ## `observedAt` is the provider's clock, not ours
 *
 * The signal's moment is "when the provider said it", and Stripe puts that on the envelope as
 * `event.created` — the instant the event was published, which is stable across a retry and
 * survives a backlog replay in the order the facts really happened. `new DateTimeImmutable` would
 * record when *we* got round to reading it, so a day-old event retried today would be filed as
 * today's. The fallback to the current moment is for a payload with no `created` on it at all,
 * which is not a shape Stripe sends; it is there so a hand-built test envelope is readable rather
 * than a 1970 timestamp.
 *
 * ## What a snapshot from here leaves at its default, and why
 *
 * `stageCode` is **null**, deliberately, and stays so. Stripe states no stage code of its own: it
 * has no field for one, and the only thing that resembles it — the `warning_` prefix — is an
 * implication rather than a value. That field is the *provider's word for the case's position in its
 * own cycle* (ConnexPay's `CaseType`, where 2 is a second chargeback in the same stage), so a null
 * here is the provider saying nothing, which is the meaning {@see DisputeSnapshot} gives it.
 *
 * The implication is drawn, and it is drawn **here** — in `$stage` and `$status`, through
 * {@see StatusMapping}, beside the code they were read from. An earlier version of this docblock put
 * that off to "the other side of this boundary", and the reasoning was wrong in one step: the other
 * side is a recorder in `src/Laravel/`, which cannot see this package and therefore cannot hold this
 * table. Leaving it there would have left `warning_needs_response` unmappable by anyone, and the
 * resolution path — which `ChargeDisputeClosedHandler` calls with Stripe's own `won` / `lost` —
 * unfiled. What §0.4 forbids is this package declaring `DisputeStage`; a mapping onto the domain's
 * *spelling* of one is a sentence about Stripe's vocabulary, and Stripe's vocabulary belongs here.
 *
 * `outcomeCode`, `waitingOnCode`, `hasResponse` and `familyRef` are ConnexPay's position signals
 * and stay null: Stripe states none of them. `fees` stays empty for a different reason — a Stripe
 * dispute's fee arrives on the charge's balance transaction, which the existing
 * `charge.updated`/`GatewayFeeRecorder` path already reads, and the dispute payload carries no fee
 * *code* that this DTO's `{code, amount, chargedAt}` shape could hold without inventing one.
 */
final readonly class DisputePayload
{
    /**
     * Stripe's own words for a case the networks decided.
     *
     * The only two statuses {@see DisputeResolution} may carry, and the reason
     * {@see ChargeDisputeClosedHandler} has to look before it reports: `warning_closed` is a
     * closed case that nobody decided — an inquiry that sat out its 120 days — and reporting it
     * through the resolution call would file a case still open for judgement as an outcome.
     */
    private const array OUTCOMES = ['won', 'lost'];

    private function __construct(
        private object $dispute,
        private string $providerEventKey,
        private DateTimeImmutable $observedAt,
    ) {}

    public static function from(Event $event): self
    {
        return new self(
            $event->data->object,
            (string) ($event->id ?? ''),
            self::observedAt($event),
        );
    }

    /**
     * Stripe's reference for the case — `dp_…`.
     *
     * Returned verbatim, including when it is blank: the snapshot refuses a blank reference with a
     * message about which contract it broke, and pre-checking it here would turn that refusal into
     * a different one further away from the field that was missing.
     */
    public function reference(): string
    {
        return (string) ($this->dispute->id ?? '');
    }

    /**
     * The PaymentIntent the case was raised against, as Stripe names it — or `''` when the payload
     * carries none.
     *
     * The empty string is the answer that decides `Skipped` rather than `Delay` in each handler, so
     * it must mean exactly "Stripe did not name one" and nothing else. That is why an expanded
     * `payment_intent` object is unwrapped rather than missed: the two would otherwise be the same
     * value, and reading the object as "absent" would silently and finally drop a live dispute —
     * the one outcome worse than delaying it.
     */
    public function paymentIntentReference(): string
    {
        $reference = $this->dispute->payment_intent ?? null;

        if (is_string($reference) && $reference !== '') {
            return $reference;
        }

        if (is_object($reference) && isset($reference->id) && is_string($reference->id)) {
            return $reference->id;
        }

        return '';
    }

    /** Stripe's own status for the case, untrimmed and unmapped — `warning_needs_response`, `lost`. */
    public function status(): string
    {
        return (string) ($this->dispute->status ?? '');
    }

    /**
     * Whether the status Stripe stated is one the networks decided.
     *
     * Not a mapping onto `DisputeStatus` — this package may not name that enum. It answers the one
     * question the handler cannot ask the DTO, because the *call* changes with the answer: an
     * outcome goes out through `onDisputeResolved()`, which is keyed on the case, and everything
     * else through `onDisputeObserved()`, which is keyed on the payment.
     */
    public function isOutcome(): bool
    {
        return in_array($this->status(), self::OUTCOMES, true);
    }

    /**
     * The case as Stripe currently states it, ready for `GatewayDisputeRecorder::onDisputeObserved()`.
     *
     * @throws InvalidArgumentException when the payload cannot be represented — see
     *                                  {@see self::cardBrand()}, and note that a blank reference,
     *                                  reason code or event key is refused by
     *                                  {@see DisputeSnapshot} itself.
     */
    public function snapshot(): DisputeSnapshot
    {
        $status = $this->status();

        return new DisputeSnapshot(
            gatewayDisputeRef: $this->reference(),
            cardBrand: $this->cardBrand(),
            reasonCode: (string) ($this->dispute->reason ?? ''),
            providerEventKey: $this->providerEventKey,
            observedAt: $this->observedAt,
            // Stripe's own word, and null when it stated none — which for a payload with no status
            // at all is the provider staying silent rather than a case with no position.
            statusCode: $status === '' ? null : $status,
            // …and beside it the domain's reading of that word. Null here is a status this table
            // does not cover, which the recorder refuses rather than defaulting: a stage Stripe has
            // added and we have not yet mapped must be seen, not filed under a guess.
            stage: StatusMapping::stage($status),
            status: StatusMapping::status($status),
            disputedAmount: $this->disputedAmount(),
            responseDueAt: $this->responseDueAt(),
        );
    }

    /**
     * The case as a decided one, ready for `GatewayDisputeRecorder::onDisputeResolved()`.
     *
     * Only call this when {@see self::isOutcome()} is true: a resolution is the two values that
     * mean money moved, and a status naming a case still under review belongs on the observed
     * call. The DTO cannot check that for itself — it carries the provider's word, and refusing
     * one is the mapper's job — so the precondition is here.
     */
    public function resolution(): DisputeResolution
    {
        $status = $this->status();

        return new DisputeResolution(
            statusCode: $status,
            providerEventKey: $this->providerEventKey,
            observedAt: $this->observedAt,
            // The two values that reach here are `won` and `lost` — the guard above is why — and
            // both are Stripe's word *and* the domain's spelling of the same thing. Passing the
            // spelling explicitly is not redundant: it is the difference between a coincidence two
            // vocabularies happen to share and the contract the recorder reads.
            status: StatusMapping::status($status),
        );
    }

    /**
     * The network the case was raised on, from `payment_method_details.card.brand`.
     *
     * Stripe states the brand on the dispute itself and not only on the charge, which is what makes
     * this readable from a webhook payload without a second API call.
     *
     * The table is {@see \Techork\PaymentService\Stripe\Webhook\Handler\PaymentMethodAttachedHandler}'s
     * — the same labels, the same one shortening (`diners` is `DinersClub` here, `dinersclub` in the
     * enum) and the same `tryFrom` for the six that map 1:1. It is written out rather than shared
     * because that one is private to the handler that owns it, and it is deliberately *not* copied
     * with its other half: that handler answers an unrecognised label with `Skipped` because
     * re-registering a card we cannot describe costs nothing, while this one refuses the delivery
     * outright. A dispute is money that has already moved, and `Skipped` is final — a `Skipped`
     * dispute is a chargeback the ledger never learns about, silently, forever.
     *
     * @throws InvalidArgumentException when Stripe states no brand this enum can hold — an
     *                                  unrecognised label (`cartes_bancaires`, `eftpos_au`,
     *                                  `interac`, `link`, `unknown`) or no
     *                                  `payment_method_details` at all. The message names the case
     *                                  and the label, because the fix is a `CardBrand` case or a
     *                                  decision about the network, and both need to know which one
     *                                  arrived.
     */
    private function cardBrand(): CardBrand
    {
        $brand = $this->dispute->payment_method_details->card->brand ?? null;

        $mapped = self::mapBrand(is_string($brand) ? $brand : '');

        return $mapped ?? throw new InvalidArgumentException(sprintf(
            'Stripe dispute "%s" states %s as its card brand, and %s has no case for it. The '
            . 'snapshot requires a network — it is half of the (brand, code) pair the evidence '
            . 'requirements are keyed on — so the delivery is refused rather than dropped.',
            $this->reference(),
            is_string($brand) ? sprintf('"%s"', $brand) : 'no brand at all',
            CardBrand::class,
        ));
    }

    private static function mapBrand(string $label): ?CardBrand
    {
        return match ($label) {
            'diners' => CardBrand::DinersClub,
            default => CardBrand::tryFrom($label),
        };
    }

    /**
     * The money in dispute, or null when Stripe stated no usable pair.
     *
     * Null means "Stripe did not state it", as the DTO requires — an amount with no currency, or a
     * currency with no amount, is not a `Money` and is reported as no amount rather than guessed
     * at. The three handlers cannot decide that this is fatal, and should not: the DTO makes the
     * amount optional on purpose, and a case a provider opened before it priced it is exactly what
     * an operator looks at. Whether a case may be *opened* without a positive amount is F1's
     * question and `OpenDisputeCommand` answers it.
     */
    private function disputedAmount(): ?Money
    {
        $amount = $this->dispute->amount ?? null;
        $currency = $this->dispute->currency ?? null;

        if (! is_int($amount) || ! is_string($currency) || $currency === '') {
            return null;
        }

        return new Money($amount, new Currency(strtoupper($currency)));
    }

    /**
     * `evidence_details.due_by`, read as the instant Stripe published it.
     *
     * Null when there is no window — a case can be raised before it has one, and Stripe omits the
     * whole `evidence_details` on some.
     */
    private function responseDueAt(): ?DateTimeImmutable
    {
        $dueBy = $this->dispute->evidence_details->due_by ?? null;

        return is_int($dueBy) ? new DateTimeImmutable('@'.$dueBy) : null;
    }

    private static function observedAt(Event $event): DateTimeImmutable
    {
        $created = $event->created ?? null;

        return is_int($created) && $created > 0
            ? new DateTimeImmutable('@'.$created)
            : new DateTimeImmutable;
    }
}
