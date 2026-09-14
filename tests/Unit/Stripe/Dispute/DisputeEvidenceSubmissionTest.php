<?php

declare(strict_types=1);

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Gateway\Command\DisputeEvidenceCommand;
use Techork\PaymentService\Gateway\ValueObject\DisputeEvidenceItem;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Stripe\Dispute\DisputeEvidenceSubmission;
use Techork\PaymentService\Stripe\Dispute\Exception\EvidenceCannotBeFiled;
use Techork\PaymentService\Stripe\Dispute\Exception\EvidenceExceedsTextLimit;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * F7's staging step: `POST /v1/disputes/:id` with an `evidence` object, and the second call with
 * `submit: true` that sends the same evidence to the network.
 *
 * The `submit` key is the whole of the dual control F7 asks for, and it is one boolean away from
 * filing a response nobody reviewed — or, the other way round, from staging evidence and never
 * sending it. So both directions are pinned on the wire, as the provider's own string: Stripe's SDK
 * encodes a boolean parameter before it reaches the transport, which is what these assertions read.
 *
 * The rest of the file is the refusal path and the provider's answer. An over-limit submission must
 * be refused before anything is uploaded: the uploads are separate calls that have already happened
 * by the time Stripe would reject the update, and a rejection after them leaves documents orphaned
 * on the account — a bill, and a puzzle for the operator who assembled them.
 *
 * There is no `payload()` to assert here, unlike every other operation in this package: a file's
 * field carries the `file_…` id the File Upload API returned, so the body is not a function of the
 * command alone. What the body contains is read back off the transport instead.
 */
function stripeDisputeSubmissionFakeApi(array $body, int $status = 200, array $others = []): object
{
    $client = new class($body, $status, $others) implements ClientInterface
    {
        /** @var list<array{url: string, params: array<string, mixed>, headers: array<int, string>}> */
        public array $calls = [];

        /** @param array<string, array<string, mixed>> $others */
        public function __construct(private array $body, private int $status, private array $others) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->calls[] = [
                'url' => (string) $absUrl,
                'params' => (array) $params,
                'headers' => (array) $headers,
            ];

            foreach ($this->others as $fragment => $body) {
                if (str_contains((string) $absUrl, $fragment)) {
                    return [json_encode($body), 200, []];
                }
            }

            return [json_encode($this->body), $this->status, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

/**
 * @param  array<array-key, DisputeEvidenceItem>  $evidence
 */
function stripeDisputeSubmission(array $evidence, bool $submit, ?string $clientUniqueId = null): DisputeEvidenceSubmission
{
    return new DisputeEvidenceSubmission(
        new StripeSettings('sk_test_fake'),
        new DisputeEvidenceCommand(
            gatewayId: GatewayId::generate(),
            disputeReference: 'dp_1QkDisputeCaseAlpha',
            evidence: $evidence,
            submit: $submit,
            clientUniqueId: $clientUniqueId,
        ),
    );
}

/** @return array<string, mixed> */
function stripeDisputeDisputeBody(string $status = 'needs_response'): array
{
    return ['id' => 'dp_1QkDisputeCaseAlpha', 'object' => 'dispute', 'status' => $status];
}

// ──────────────────────────────────────────────
//  the two calls F7 calls a dual control
// ──────────────────────────────────────────────

it('stages the evidence with submit false and leaves the case where it is', function () {
    $api = stripeDisputeSubmissionFakeApi(stripeDisputeDisputeBody());

    $result = stripeDisputeSubmission(
        [new DisputeEvidenceItem('statement_descriptor', 'ACME*SUBSCRIPTION')],
        submit: false,
    )->submit();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('dp_1QkDisputeCaseAlpha')
        ->and($result->message)->toBeNull()
        ->and($api->calls)->toHaveCount(1)
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/disputes/dp_1QkDisputeCaseAlpha')
        ->and($api->calls[0]['params'])->toBe([
            'evidence' => ['uncategorized_text' => 'statement_descriptor: ACME*SUBSCRIPTION'],
            // Stripe's SDK encodes a boolean parameter before it reaches the transport. `false`
            // here is the whole difference between staging evidence and filing it.
            'submit' => 'false',
        ]);
});

it('sends the same evidence with submit true', function () {
    $api = stripeDisputeSubmissionFakeApi(stripeDisputeDisputeBody('under_review'));

    stripeDisputeSubmission(
        [new DisputeEvidenceItem('statement_descriptor', 'ACME*SUBSCRIPTION')],
        submit: true,
    )->submit();

    expect($api->calls[0]['params']['submit'])->toBe('true');
});

/**
 * Evidence is not safe to file twice, so the caller's key reaches Stripe as a request option — the
 * adapter in `Laravel/Port` is what composes it from the case, the step and the evidence, and this
 * pins that the operation forwards it rather than only holding it.
 */
it('sends the caller idempotency key as a Stripe request option, scoped to the evidence call', function () {
    $api = stripeDisputeSubmissionFakeApi(stripeDisputeDisputeBody());

    stripeDisputeSubmission(
        [new DisputeEvidenceItem('statement_descriptor', 'ACME')],
        submit: false,
        clientUniqueId: 'dp_1QkDisputeCaseAlpha:stage:abc123',
    )->submit();

    expect($api->calls[0]['headers'])->toContain('Idempotency-Key: dp_1QkDisputeCaseAlpha:stage:abc123:dispute-evidence');
});

/**
 * A document cannot travel in a text field: the File Upload call returns the `file_…` id, and that
 * id is what the evidence object carries. Both facts are pinned together here, because a submission
 * that sent the base64 instead would look correct in every assertion about the text fields.
 */
it('uploads the documents and puts their ids in the evidence object', function () {
    $api = stripeDisputeSubmissionFakeApi(stripeDisputeDisputeBody(), others: [
        '/v1/files' => ['id' => 'file_1QkDeliveryNote', 'object' => 'file', 'purpose' => 'dispute_evidence'],
    ]);

    stripeDisputeSubmission([
        new DisputeEvidenceItem('proof_of_delivery_or_service', base64_encode('%PDF-1.4 note'), 'application/pdf'),
        new DisputeEvidenceItem('statement_descriptor', 'ACME'),
    ], submit: true)->submit();

    $upload = $api->calls[0];
    $update = $api->calls[1];

    expect($upload['url'])->toContain('/v1/files')
        ->and($upload['params']['purpose'])->toBe('dispute_evidence')
        ->and($update['url'])->toContain('/v1/disputes/dp_1QkDisputeCaseAlpha')
        ->and($update['params']['evidence'])->toBe([
            'uncategorized_text' => 'statement_descriptor: ACME',
            'service_documentation' => 'file_1QkDeliveryNote',
        ]);
});

// ──────────────────────────────────────────────
//  refusals, before any call is made
// ──────────────────────────────────────────────

/**
 * The 150,000-character ceiling F7 asks for, enforced where it must be: the whole submission is
 * refused, with a typed exception, and nothing was uploaded on the way to the refusal — which is
 * the difference between an operator retrying a shorter response and one paying for documents
 * uploaded for a call Stripe never accepted.
 */
it('refuses an over-limit submission without uploading anything', function () {
    $api = stripeDisputeSubmissionFakeApi(stripeDisputeDisputeBody());

    expect(fn () => stripeDisputeSubmission([
        new DisputeEvidenceItem('statement_descriptor', str_repeat('a', 150_000)),
        new DisputeEvidenceItem('customer_correspondence', base64_encode('%PDF-1.4 note'), 'application/pdf'),
    ], submit: false)->submit())
        ->toThrow(EvidenceExceedsTextLimit::class, 'dp_1QkDisputeCaseAlpha')
        ->toThrow(EvidenceExceedsTextLimit::class, '150000');

    expect($api->calls)->toBe([]);
});

/** A document has no field on this fact at all, and no call would change that. */
it('refuses a document for a fact whose field takes text, without calling anything', function () {
    $api = stripeDisputeSubmissionFakeApi(stripeDisputeDisputeBody());

    expect(fn () => stripeDisputeSubmission([
        new DisputeEvidenceItem('buyer_ip_address', base64_encode('%PDF-1.4 note'), 'application/pdf'),
    ], submit: false)->submit())
        ->toThrow(EvidenceCannotBeFiled::class, 'buyer_ip_address');

    expect($api->calls)->toBe([]);
});

// ──────────────────────────────────────────────
//  the provider's answer
// ──────────────────────────────────────────────

/**
 * A case the network will not take evidence for is an ordinary failed call, not an exception:
 * Stripe's message names the state it objected to, and it is the only explanation an operator gets.
 */
it('converts a Stripe API error into a failed result carrying the reason', function () {
    stripeDisputeSubmissionFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'This dispute is already under review']],
        400,
    );

    $result = stripeDisputeSubmission([new DisputeEvidenceItem('statement_descriptor', 'ACME')], submit: true)->submit();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toBe('This dispute is already under review');
});

/** The upload is part of the call: a document that could not be filed means nothing was filed. */
it('reports the whole submission failed when a document could not be uploaded', function () {
    $api = stripeDisputeSubmissionFakeApi(
        ['error' => ['type' => 'api_error', 'message' => 'The file could not be uploaded']],
        400,
    );

    $result = stripeDisputeSubmission([
        new DisputeEvidenceItem('proof_of_delivery_or_service', base64_encode('%PDF-1.4 note'), 'application/pdf'),
    ], submit: true)->submit();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('The file could not be uploaded')
        // The update was never attempted: a response with no document to point at is not the
        // answer the caller asked for, and sending it would file an incomplete response.
        ->and($api->calls)->toHaveCount(1)
        ->and($api->calls[0]['url'])->toContain('/v1/files');
});

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});
