<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Dispute;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Gateway\Command\DisputeEvidenceCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\ValueObject\DisputeEvidenceItem;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * Files a case's evidence: the File Upload calls, then `POST /v1/disputes/:id`.
 *
 * ## The two calls, and the draft → review → submit flow they make
 *
 * `submit: false` stages the evidence on the dispute — in the API and the dashboard, invisible to
 * the issuer — and `submit: true` sends it. That pair is a ready-made dual control and F7 says to
 * use it rather than invent one, so the two are one operation with one flag: the request differs by
 * one key, and the case's own submission axis (`DRAFT` then `SUBMITTED`) is what the caller records
 * from the command it sent.
 *
 * ## There is no `payload()`, unlike every other operation in this package
 *
 * `payload()` elsewhere is the request body as a value, which a test can assert without a network
 * call and a reader can read beside the call it becomes. It cannot be one here: a file's field
 * carries the `file_…` id that the File Upload API returned, so the body is not a function of the
 * command alone, and a `payload()` that did not upload would describe a request that cannot be
 * sent. What the body contains is asserted from the transport in the test instead.
 *
 * ## What is validated here, and what is left to Stripe
 *
 * Everything this side can see is checked before anything is sent: the facts are composed into
 * Stripe's fields ({@see EvidenceFieldMapping}), and the result is refused if the text is over
 * Stripe's limit or a document has no field to go in. Those refusals must not arrive after the
 * uploads — an orphaned document on the account is a bill and a puzzle — which is why composition
 * happens before the first network call rather than beside the second.
 *
 * The status is deliberately NOT read back the way {@see DisputeConcession} reads its own: a staged
 * submission leaves the case in the status it was, a filed one moves `needs_response` to
 * `under_review`, an inquiry moves to the `warning_` form of the same, and a late response to an
 * `under_review` case may leave it where it is — four legitimate answers on one axis, and a guard
 * over them would refuse the calls it exists to make. Stripe errors when it will not take the
 * evidence at all, and that is the case this must not report as a success.
 */
final readonly class DisputeEvidenceSubmission
{
    use StripeRequestParameters;

    public function __construct(
        private StripeSettings $settings,
        private DisputeEvidenceCommand $command,
    ) {}

    /**
     * The case's reference, or a failed result carrying Stripe's own message.
     *
     * A failure here covers the uploads as well as the update: from the caller's side the question
     * is whether the evidence was filed, and a document that could not be uploaded means it was
     * not. Stripe's message is passed through rather than restated — it names the field it refused,
     * and a paraphrase would lose that.
     *
     * @throws \Techork\PaymentService\Stripe\Dispute\Exception\EvidenceExceedsTextLimit
     * @throws \Techork\PaymentService\Stripe\Dispute\Exception\EvidenceCannotBeFiled
     */
    public function submit(): GatewayResult
    {
        $block = EvidenceFieldMapping::of($this->command->disputeReference, $this->command->evidence());
        $upload = new DisputeFileUpload($this->settings);

        try {
            // `evidence` is keyed by the provider's own field names, which are the mapping's to
            // know, and `submit` rides beside it — false for the staging call, true for the one
            // that sends.
            $params = [
                'evidence' => $block->resolvedWith(
                    static fn (DisputeEvidenceItem $item): string => $upload->upload($item),
                ),
                'submit' => $this->command->submit,
            ];

            /**
             * @psalm-suppress ArgumentTypeCoercion the SDK declares this endpoint's body as an
             * exhaustive shape of some twenty-odd optional keys, and psalm reports any body that is
             * not a literal restatement of it — the same coercion it reports for `Charge` and
             * `Authorize`, which psalm.xml suppresses for those two files. The keys here are the
             * mapping's, spelled once in {@see EvidenceFieldMapping} and asserted against Stripe's
             * own recorded payloads in this package's tests; restating the shape in a docblock would
             * be an unchecked claim that drifts from that table.
             */
            $dispute = (new StripeClient($this->settings->apiKey))->disputes->update(
                $this->command->disputeReference,
                $params,
                $this->stripeOpts($this->command->clientUniqueId, 'dispute-evidence'),
            );

            return GatewayResult::succeeded($dispute->id);
        } catch (ApiErrorException $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }
}
