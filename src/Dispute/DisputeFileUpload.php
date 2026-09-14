<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Dispute;

use CURLFile;
use InvalidArgumentException;
use RuntimeException;
use Stripe\File;
use Stripe\StripeClient;
use Techork\PaymentService\Gateway\ValueObject\DisputeEvidenceItem;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Techork\PaymentService\Stripe\Dispute\Exception\EvidenceCannotBeFiled;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * The File Upload API, with purpose `dispute_evidence`, returning the id the evidence field carries.
 *
 * ## Why bytes go through a temporary file
 *
 * Stripe's SDK uploads a document as a multipart part, and the only thing it can be handed for one
 * is a `CURLFile` — a path, not a string. Our content is base64 in a value object, so the bytes are
 * written to a temp file, uploaded from there, and the file is removed in a `finally` whether the
 * upload answered or threw: a document from a customer's correspondence does not belong on disk
 * after the call, and a leaked temp file survives every retry. Failure to remove it is swallowed
 * deliberately — the upload has already succeeded by then, and turning that into an exception would
 * report a filing that did not happen.
 *
 * ## Why the idempotency key is derived from the document
 *
 * Stripe caches an idempotency key for 24 hours and replays the first response for a repeat, so the
 * key decides what "this same call" means. Keyed on the case and the step alone, a corrected
 * document re-sent within the day would come back as the previous upload's answer — the same file
 * id — and the correction would never reach the network. Keyed on the bytes and the fact they
 * answer, the same document re-offered is a genuine duplicate (answered with the same id, no second
 * upload) and a different document is a different call. The whole digest is used rather than a
 * prefix: two documents that collide on a prefix would be one call as far as Stripe is concerned.
 */
final readonly class DisputeFileUpload
{
    use StripeRequestParameters;

    public function __construct(private StripeSettings $settings) {}

    /**
     * Uploads one document and answers with Stripe's `file_…` for it.
     *
     * @throws EvidenceCannotBeFiled when the content is not base64
     * @throws \Stripe\Exception\ApiErrorException when Stripe refuses the upload — left to the
     *   caller, which is the operation that knows what it was doing when the upload failed
     */
    public function upload(DisputeEvidenceItem $item): string
    {
        $mediaType = $item->mediaType ?? throw new InvalidArgumentException(
            "Evidence of type \"{$item->type}\" has no media type, so there is nothing to upload. "
            . 'A text fact belongs in the evidence object rather than in a file part.',
        );

        $bytes = base64_decode($item->content, true);
        $bytes === false && throw EvidenceCannotBeFiled::undecodable($item->type);

        $path = tempnam(sys_get_temp_dir(), 'stripe-dispute-evidence');
        $path === false && throw new RuntimeException(
            'Stripe dispute evidence could not be staged for upload: no temporary file could be '
            . 'created. The upload needs one, because the SDK sends a document as a path rather '
            . 'than as bytes.',
        );

        try {
            file_put_contents($path, $bytes) === false && throw new RuntimeException(
                "Stripe dispute evidence could not be written to '{$path}' for upload.",
            );

            $file = (new StripeClient($this->settings->apiKey))->files->create(
                [
                    'file' => new CURLFile($path, $mediaType, self::postFilename($mediaType)),
                    'purpose' => File::PURPOSE_DISPUTE_EVIDENCE,
                ],
                // Scoped by fact as well as by bytes: the same document can legitimately answer two
                // different facts on two different cases, and those are two uploads Stripe should
                // make rather than one it replays.
                $this->stripeOpts(hash('sha256', $item->content), $item->type),
            );

            return $file->id;
        } finally {
            // Not `unlink()` bare: a failure here would replace a successful upload's answer with
            // an exception about the garbage, and the document has already reached Stripe.
            @unlink($path);
        }
    }

    /**
     * The name the part carries into Stripe's dashboard.
     *
     * Named from the media type rather than from the fact: a fact's name is caller-supplied text,
     * and a filename built out of it is one more string crossing a multipart boundary for nothing.
     */
    private static function postFilename(string $mediaType): string
    {
        return 'dispute-evidence.'.match ($mediaType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            default => 'bin',
        };
    }
}
