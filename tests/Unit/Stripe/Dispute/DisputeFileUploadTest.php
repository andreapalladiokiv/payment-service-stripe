<?php

declare(strict_types=1);

use Stripe\ApiRequestor;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Gateway\ValueObject\DisputeEvidenceItem;
use Techork\PaymentService\Stripe\Dispute\DisputeFileUpload;
use Techork\PaymentService\Stripe\Dispute\Exception\EvidenceCannotBeFiled;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * The File Upload API, which is the only place in this domain a document leaves our process.
 *
 * Three things are pinned and each has cost money when it is wrong elsewhere: the purpose
 * (`dispute_evidence` — Stripe charges for uploads under no purpose and refuses the evidence field
 * nothing can point at), that what reaches the wire is the decoded bytes rather than the base64
 * text the domain carries, and that the temporary file the SDK needs does not outlive the call.
 *
 * The fake transport reads the staged file at the moment the request is made, because the uploader
 * removes it in a `finally`: by the time the assertion runs there is nothing left to read, and that
 * is the third of the three facts this file exists to pin.
 */
function stripeDisputeUploadFakeApi(array $body, int $status = 200): object
{
    $client = new class($body, $status) implements ClientInterface
    {
        /** @var list<array{url: string, params: array<string, mixed>, bytes: ?string, path: ?string}> */
        public array $calls = [];

        public function __construct(private array $body, private int $status) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $file = is_array($params) ? ($params['file'] ?? null) : null;
            $path = $file instanceof CURLFile ? $file->getFilename() : null;

            $this->calls[] = [
                'url' => (string) $absUrl,
                'params' => (array) $params,
                'bytes' => $path === null ? null : (string) file_get_contents($path),
                'path' => $path,
            ];

            return [json_encode($this->body), $this->status, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

function stripeDisputeUpload(): DisputeFileUpload
{
    return new DisputeFileUpload(new StripeSettings('sk_test_fake'));
}

/** The File Upload API lives on its own host in Stripe's SDK, so the fake's URL says which it was. */
it('uploads the decoded bytes with purpose dispute_evidence and answers with the file id', function () {
    $api = stripeDisputeUploadFakeApi(['id' => 'file_1QkEvidence', 'object' => 'file', 'purpose' => 'dispute_evidence']);

    $id = stripeDisputeUpload()->upload(new DisputeEvidenceItem(
        'proof_of_delivery_or_service',
        base64_encode('%PDF-1.4 a delivery note'),
        'application/pdf',
    ));

    expect($id)->toBe('file_1QkEvidence')
        ->and($api->calls)->toHaveCount(1)
        ->and($api->calls[0]['url'])->toContain('/v1/files')
        ->and($api->calls[0]['params']['purpose'])->toBe('dispute_evidence')
        // The bytes, not the base64 text the domain carries: uploading the encoded form uploads a
        // corrupt document, and Stripe answers that with a decode error about a part it received.
        ->and($api->calls[0]['bytes'])->toBe('%PDF-1.4 a delivery note')
        ->and($api->calls[0]['params']['file'])->toBeInstanceOf(CURLFile::class)
        ->and($api->calls[0]['params']['file']->getMimeType())->toBe('application/pdf');
});

/** A JPEG is the other format Stripe's dispute evidence takes, and it was never uploaded before. */
it('uploads a JPEG under its own content type', function () {
    $api = stripeDisputeUploadFakeApi(['id' => 'file_1QkPhoto', 'object' => 'file']);

    stripeDisputeUpload()->upload(new DisputeEvidenceItem(
        'customer_correspondence',
        base64_encode("\xFF\xD8\xFF\xE0 a photo"),
        'image/jpeg',
    ));

    expect($api->calls[0]['params']['file']->getMimeType())->toBe('image/jpeg')
        ->and($api->calls[0]['bytes'])->toBe("\xFF\xD8\xFF\xE0 a photo");
});

/**
 * A document from a customer's correspondence does not belong on disk after the call, and a leaked
 * temp file survives every retry — so the removal happens whether the upload answered or threw.
 */
it('removes the staged file whatever the provider answered', function () {
    $api = stripeDisputeUploadFakeApi(['error' => ['type' => 'api_error', 'message' => 'boom']], 500);

    try {
        stripeDisputeUpload()->upload(new DisputeEvidenceItem('customer_correspondence', base64_encode('x'), 'application/pdf'));
    } catch (ApiErrorException) {
        // Expected: the uploader leaves Stripe's own exception to the operation that knows what it
        // was doing when the upload failed, and the file it staged is what this test is about.
    }

    expect($api->calls[0]['path'])->not->toBeNull()
        ->and(file_exists($api->calls[0]['path']))->toBeFalse();
});

/** The domain's item refuses this too, but a provider that finds it anyway must refuse, not send. */
it('refuses content that is not base64', function () {
    $api = stripeDisputeUploadFakeApi(['id' => 'file_never']);

    expect(fn () => stripeDisputeUpload()->upload(new DisputeEvidenceItem(
        'customer_correspondence',
        'raw bytes, not base64',
        'application/pdf',
    )))->toThrow(EvidenceCannotBeFiled::class, 'customer_correspondence');

    expect($api->calls)->toBe([]);
});

it('refuses an item that is text rather than a document', function () {
    $api = stripeDisputeUploadFakeApi(['id' => 'file_never']);

    expect(fn () => stripeDisputeUpload()->upload(new DisputeEvidenceItem('statement_descriptor', 'ACME')))
        ->toThrow(InvalidArgumentException::class, 'no media type');

    expect($api->calls)->toBe([]);
});

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});
