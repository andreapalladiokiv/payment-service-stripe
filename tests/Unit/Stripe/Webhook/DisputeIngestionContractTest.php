<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\Domain\Dispute\Command\OpenDisputeCommand;
use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\InstrumentReferenceEraser;
use Techork\PaymentService\Gateway\Webhook\Contract\StoredWebhookCall;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeResolution;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayCancellationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundProcessingRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\UnmatchedDispute;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;
use Techork\PaymentService\Stripe\Webhook\EventParser;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeDisputeClosedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeDisputeCreatedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeDisputeUpdatedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeRefundedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeRefundUpdatedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\ChargeUpdatedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentIntentCanceledHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentIntentFailedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentIntentSucceededHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentMethodAttachedHandler;
use Techork\PaymentService\Stripe\Webhook\Handler\PaymentMethodDetachedHandler;
use Techork\PaymentService\Stripe\Webhook\SignatureVerifier;
use Techork\PaymentService\Stripe\Webhook\StripeWebhookSubscriber;

/**
 * The Stripe dispute contract, driven end to end from the raw bytes Stripe sends.
 *
 * ## What this file is, and what it is not
 *
 * This is F0's Stripe contract test, written in place of the recorded payloads F0 asks for: the
 * bodies under `tests/Fixtures/Disputes/` are **hand-built from Stripe's documented shapes and are
 * named `.doc-sample.json` for that reason** — F0's own convention, so that real recorded payloads
 * can replace them in place later. Nothing here was captured from Stripe.
 *
 * They live as raw bytes because that is the only way to test the two things a fixture is for and an
 * inline factory cannot do: that a delivery **verifies from its bytes exactly as delivered** (the
 * signature is an HMAC over the body, so a body re-serialised from a PHP array is a different
 * delivery), and that the event id on the envelope is what the machinery stores and the aggregate
 * compares. The behavioural cases live in the three handler tests, built inline as this package's
 * house style prefers.
 *
 * ## The one place a Stripe test reaches into the domain, and why
 *
 * F0's done-condition is that this contract test shows a dispute that **arrives already `lost`** and
 * that **a second dispute on the same PaymentIntent produces a second `DisputeId`**. Both of those
 * are statements about F1's aggregate — `DisputeId` is the aggregate's own identity and nothing in
 * `src/Stripe/` may name it — so they cannot be asserted anywhere below the boundary. The mapping
 * from the snapshot's codes onto `DisputeStage`/`DisputeStatus` at the bottom of this file is the
 * **application's**, stood in for here because the application's recorder is not written yet; F3 is
 * ingestion. The arch hierarchy in `tests/Arch/PackageHierarchyTest.php` constrains `src/`, not the
 * suites, and this test is the only place that reaches across.
 *
 * Pest helpers are global, so every function in this file is named for this file.
 */
function disputeContractFixtureDirectory(): string
{
    return dirname(__DIR__, 3).'/Fixtures/Disputes';
}

/** The fixture's bytes, not a decoded array: the signature is over these exactly. */
function disputeContractBody(string $fixture): string
{
    $path = disputeContractFixtureDirectory().'/'.$fixture;

    is_file($path) || throw new RuntimeException("missing dispute fixture {$fixture}");

    return (string) file_get_contents($path);
}

/** @return array<string, mixed> */
function disputeContractPayload(string $fixture): array
{
    return json_decode(disputeContractBody($fixture), true, flags: JSON_THROW_ON_ERROR);
}

function disputeContractSecret(): string
{
    return 'whsec_'.bin2hex(random_bytes(8));
}

function disputeContractCredential(string $secret): GatewayCredential
{
    return new readonly class($secret) implements GatewayCredential
    {
        public function __construct(private string $secret) {}

        public function getId(): GatewayId
        {
            return GatewayId::fromString('01929fa5-0000-7000-8000-0000000000c1');
        }

        public function getGatewayName(): string
        {
            return 'stripe';
        }

        public function getCredentials(): array
        {
            return ['webhook_signing_key' => $this->secret];
        }
    };
}

/** A delivery signed the way Stripe signs one: `t=<ts>,v1=hmac(ts.body)`, over the raw bytes. */
function disputeContractRequest(string $body, string $secret): ServerRequestInterface
{
    $timestamp = time();
    $header = sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$body, $secret));

    $factory = new Psr17Factory;

    return $factory->createServerRequest('POST', 'https://merchant.example/webhooks/stripe')
        ->withHeader('Stripe-Signature', $header)
        ->withBody($factory->createStream($body))
        ->withParsedBody(json_decode($body, true));
}

/**
 * `gateway_references`, in memory: the row a case's provider reference lives in.
 *
 * ## Why the recorder needs one at all
 *
 * The provider's reference for a case is no longer anywhere near the aggregate — `DisputeAggregate`,
 * `OpenDisputeCommand` and the dispute port requests all speak our `DisputeId` and nothing else —
 * and this table is now the only place the two names are held side by side. The recorder is the one
 * component that holds both halves at once, because it mints or finds our id at the moment it reads
 * the provider's reference off the delivery, so it is the writer of the row; and a contract test
 * whose recorder did not write one would be showing a case that no adapter could ever address.
 *
 * ## Why a fake, and not the Eloquent table
 *
 * The real implementation is pinned against an in-memory SQLite Capsule in the Laravel package, where
 * the schema, the morph type and the upsert rule are the subject. This is a Stripe contract test over
 * raw delivered bytes, and Stripe's package has no database at all; booting one here would put a
 * second copy of the Laravel harness in the wrong package to answer a question about a map. So the
 * table is a map — and it is faithful on the one point this file depends on, which is also the point
 * the real one turns on: a dispute's row is keyed by our aggregate id, ours being globally unique,
 * with the gateway not part of the lookup.
 */
final class DisputeContractReferences implements GatewayTransactionRepository
{
    /** @var array<string, string> our dispute id => the provider's reference for that case */
    private array $disputes = [];

    public function findForDispute(string $disputeId): ?string
    {
        return $this->disputes[$disputeId] ?? null;
    }

    public function saveForDispute(GatewayId $gatewayId, string $disputeId, string $reference): void
    {
        $this->disputes[$disputeId] = $reference;
    }

    // Nothing in a Stripe dispute delivery's path touches the other two reference kinds, and a
    // silent answer here would let a mis-wiring pass as "no row". Refusing is what this file's
    // fakes do with a call nobody asked for.

    public function findForPaymentIntent(string $paymentIntentId): ?string
    {
        throw new RuntimeException("A dispute recorder asked for payment intent '{$paymentIntentId}'.");
    }

    public function saveForPaymentIntent(GatewayId $gatewayId, string $paymentIntentId, string $reference, array $metadata = []): void
    {
        throw new RuntimeException("A dispute recorder wrote the payment intent '{$paymentIntentId}'.");
    }

    public function findMetadataForPaymentIntent(string $paymentIntentId): array
    {
        throw new RuntimeException("A dispute recorder asked for the metadata of payment intent '{$paymentIntentId}'.");
    }

    public function findForRefund(string $refundId): ?string
    {
        throw new RuntimeException("A dispute recorder asked for refund '{$refundId}'.");
    }

    public function saveForRefund(GatewayId $gatewayId, string $refundId, string $reference): void
    {
        throw new RuntimeException("A dispute recorder wrote refund '{$refundId}'.");
    }
}

/**
 * The recorder, as an implementation has to behave for the two F0 clauses to hold: one provider
 * reference names one case, so the identifier is remembered **against the reference** rather than
 * minted per call. A recorder that minted a fresh id on every delivery would report a second
 * `DisputeId` for a retry of the first case, which is the failure the clause is about.
 *
 * The same identity is what the reference row is written under, and it is written on both paths: a
 * case observed for the first time, and a resolution that arrives addressed by the provider's own
 * name. Either can be the first time we hear of the case, which is exactly why the row cannot be
 * written from the aggregate — the aggregate does not have the provider's reference any more.
 */
function disputeContractRecorder(DisputeContractReferences $references): GatewayDisputeRecorder
{
    return new class($references) implements GatewayDisputeRecorder
    {
        /** @var list<array{0: GatewayId, 1: string, 2: DisputeSnapshot}> */
        public array $observed = [];

        /** @var list<array{0: GatewayId, 1: string, 2: DisputeResolution}> */
        public array $resolved = [];

        /** @var array<string, DisputeId> the case each provider reference names */
        public array $disputeIds = [];

        public function __construct(private readonly DisputeContractReferences $references) {}

        public function onDisputeObserved(GatewayId $gatewayId, string $paymentIntentId, DisputeSnapshot $snapshot): RecorderOutcome
        {
            $this->observed[] = [$gatewayId, $paymentIntentId, $snapshot];

            $id = $this->disputeIds[$snapshot->gatewayDisputeRef] ??= DisputeId::generate();
            $this->references->saveForDispute($gatewayId, $id->toString(), $snapshot->gatewayDisputeRef);

            return RecorderOutcome::Applied;
        }

        public function onDisputeResolved(GatewayId $gatewayId, string $disputeRef, DisputeResolution $resolution): RecorderOutcome
        {
            $this->resolved[] = [$gatewayId, $disputeRef, $resolution];

            $id = $this->disputeIds[$disputeRef] ??= DisputeId::generate();
            $this->references->saveForDispute($gatewayId, $id->toString(), $disputeRef);

            return RecorderOutcome::Applied;
        }

        public function onUnmatchedDispute(GatewayId $gatewayId, UnmatchedDispute $case): RecorderOutcome
        {
            return RecorderOutcome::Applied;
        }
    };
}

/** The references this corpus uses, and the aggregate ids they stand for. */
function disputeContractResolver(): TransactionIdResolver
{
    return new readonly class implements TransactionIdResolver
    {
        private const array KNOWN = [
            'pi_1QkPaymentIntentShared' => '01929fa5-0000-7000-8000-0000000000b1',
            'pi_1QkPaymentIntentUnchallengeable' => '01929fa5-0000-7000-8000-0000000000b2',
            'pi_1QkPaymentIntentInquiry' => '01929fa5-0000-7000-8000-0000000000b3',
        ];

        public function resolvePaymentIntent(GatewayId $gatewayId, string $reference): ?string
        {
            return self::KNOWN[$reference] ?? null;
        }

        public function resolveRefund(GatewayId $gatewayId, string $reference): ?string
        {
            return null;
        }
    };
}

/** @return array{0: WebhookRouter, 1: GatewayDisputeRecorder, 2: string, 3: DisputeContractReferences} */
function disputeContractHarness(): array
{
    $secret = disputeContractSecret();
    $credential = disputeContractCredential($secret);
    $resolver = disputeContractResolver();
    $references = new DisputeContractReferences;
    $recorder = disputeContractRecorder($references);

    $subscriber = new StripeWebhookSubscriber(
        new SignatureVerifier,
        new EventParser,
        new PaymentIntentSucceededHandler($resolver, Mockery::mock(GatewaySuccessRecorder::class)),
        new PaymentIntentCanceledHandler($resolver, Mockery::mock(GatewayCancellationRecorder::class)),
        new PaymentIntentFailedHandler($resolver, Mockery::mock(GatewayFailureRecorder::class)),
        new ChargeRefundedHandler($resolver, Mockery::mock(RefundProcessingRecorder::class)),
        new ChargeUpdatedHandler($resolver, Mockery::mock(GatewayFeeRecorder::class), Mockery::mock(GatewayCredentialRepository::class)),
        new ChargeRefundUpdatedHandler($resolver, Mockery::mock(GatewayFeeRecorder::class), Mockery::mock(GatewayCredentialRepository::class)),
        new PaymentMethodAttachedHandler(Mockery::mock(GatewayPaymentMethodRecorder::class)),
        new PaymentMethodDetachedHandler(Mockery::mock(InstrumentReferenceEraser::class)),
        new ChargeDisputeCreatedHandler($resolver, $recorder),
        new ChargeDisputeUpdatedHandler($resolver, $recorder),
        new ChargeDisputeClosedHandler($resolver, $recorder),
    );

    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;
    $subscriber->subscribe($verifiers, $handlers);

    $repository = new readonly class($credential) implements GatewayCredentialRepository
    {
        public function __construct(private GatewayCredential $credential) {}

        public function findOrFail(GatewayId $gatewayId): GatewayCredential
        {
            return $this->credential;
        }

        public function all(): iterable
        {
            return [$this->credential];
        }
    };

    return [new WebhookRouter($repository, $verifiers, $handlers), $recorder, $secret, $references];
}

/**
 * The application-side mapping, which is now only the reading of it.
 *
 * §F3's table used to be stood in for here — `warning_*` is an inquiry, everything else a
 * chargeback, the statuses one to one — because the snapshot carried Stripe's codes and nothing
 * beside them. It does not any more: `DisputePayload::snapshot()` fills `$stage` and `$status`
 * through the real {@see StatusMapping}, so the two clauses below read the production table rather
 * than a copy of it, and this file would fail if that table stopped placing a case.
 *
 * What remains here is the **refusal**, and it is the half that has to stay: a status Stripe states
 * and the table does not cover arrives as a null spelling, and a null must never become a stage. The
 * aggregate's own docblock forbids a default, so the harness throws the way a real recorder would
 * and the delivery is visible rather than filed under a guess.
 *
 * What is *not* here any more is the reference. `OpenDisputeCommand` no longer carries the provider's
 * name for the case, and neither does the aggregate it opens: that name lives in the reference table,
 * written by the recorder above, and the snapshot's own `$gatewayDisputeRef` — a plain string, as it
 * has always been — goes straight there rather than through this mapping.
 */
function disputeContractCommand(DisputeSnapshot $snapshot, string $paymentIntentId, DisputeId $disputeId): OpenDisputeCommand
{
    $stage = DisputeStage::tryFrom((string) $snapshot->stage)
        ?? throw new RuntimeException("no DisputeStage beside Stripe's \"{$snapshot->statusCode}\"");

    $status = DisputeStatus::tryFrom((string) $snapshot->status)
        ?? throw new RuntimeException("no DisputeStatus beside Stripe's \"{$snapshot->statusCode}\"");

    $deadline = $snapshot->responseDueAt;

    return new class(
        $disputeId,
        PaymentIntentId::fromString($paymentIntentId),
        $stage,
        $status,
        DisputeReason::fromProviderCode($snapshot->cardBrand, $snapshot->reasonCode),
        $snapshot->disputedAmount ?? throw new RuntimeException('a dispute with no amount cannot be opened'),
        $deadline,
        $snapshot->stageCode,
        new DisputeSignal($snapshot->providerEventKey, $snapshot->observedAt),
    ) implements OpenDisputeCommand {
        public function __construct(
            private readonly DisputeId $id,
            private readonly PaymentIntentId $paymentIntent,
            private readonly DisputeStage $stage,
            private readonly DisputeStatus $status,
            private readonly DisputeReason $reason,
            private readonly Money $amount,
            private readonly ?DateTimeImmutable $deadline,
            private readonly ?string $code,
            private readonly DisputeSignal $signal,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function paymentIntentId(): PaymentIntentId
        {
            return $this->paymentIntent;
        }

        public function stage(): DisputeStage
        {
            return $this->stage;
        }

        public function status(): DisputeStatus
        {
            return $this->status;
        }

        public function reason(): DisputeReason
        {
            return $this->reason;
        }

        public function disputedAmount(): Money
        {
            return $this->amount;
        }

        public function deadlineAt(): ?DateTimeImmutable
        {
            return $this->deadline;
        }

        public function providerCode(): ?string
        {
            return $this->code;
        }

        public function signal(): DisputeSignal
        {
            return $this->signal;
        }
    };
}

it('verifies a recorded delivery from its raw bytes and reads the event id as the idempotency key', function (string $fixture, string $eventId) {
    // The bytes are the delivery. The signature is an HMAC over them, so a payload re-serialised
    // from a PHP array would not verify — which is why the fixtures are files and not factories.
    [$router, , $secret] = disputeContractHarness();

    $match = $router->identifyGateway(disputeContractRequest(disputeContractBody($fixture), $secret));

    expect($match)->not->toBeNull()
        ->and($match->kind)->toBe('stripe')
        ->and($match->externalId)->toBe($eventId);
})->with([
    'a case raised' => ['charge.dispute.created.doc-sample.json', 'evt_1QkDisputeCreatedAlpha'],
    'a second case on the same payment' => ['charge.dispute.created.second-case.doc-sample.json', 'evt_1QkDisputeCreatedBeta'],
    'a case raised already lost' => ['charge.dispute.created.already-lost.doc-sample.json', 'evt_1QkDisputeCreatedGamma'],
    'an inquiry' => ['charge.dispute.updated.inquiry.doc-sample.json', 'evt_1QkDisputeUpdatedDelta'],
    'a case won' => ['charge.dispute.closed.won.doc-sample.json', 'evt_1QkDisputeClosedAlpha'],
    'a case lost' => ['charge.dispute.closed.lost.doc-sample.json', 'evt_1QkDisputeClosedGamma'],
    'an inquiry that closed undecided' => ['charge.dispute.closed.warning-closed.doc-sample.json', 'evt_1QkDisputeClosedDelta'],
]);

it('dispatches a stored dispute delivery through the parser into its handler', function () {
    // The second half of the wire path: the parser rebuilds a Stripe\Event from the stored payload
    // and the handler reads `data.object` off it. A mismatch between those shapes is invisible to a
    // per-class test and would leave every dispute unrecorded.
    [$router, $recorder, $secret] = disputeContractHarness();

    $payload = disputeContractPayload('charge.dispute.created.doc-sample.json');
    $match = $router->identifyGateway(disputeContractRequest(disputeContractBody('charge.dispute.created.doc-sample.json'), $secret));

    $outcome = $router->dispatch(new StoredWebhookCall('stripe', $match->gatewayId, $payload));

    expect($outcome)->toBe(HandlerOutcome::Processed)
        ->and($recorder->observed)->toHaveCount(1);

    [, $paymentIntentId, $snapshot] = $recorder->observed[0];

    expect($paymentIntentId)->toBe('01929fa5-0000-7000-8000-0000000000b1')
        ->and($snapshot->gatewayDisputeRef)->toBe('dp_1QkDisputeCaseAlpha')
        ->and($snapshot->providerEventKey)->toBe('evt_1QkDisputeCreatedAlpha')
        ->and($snapshot->statusCode)->toBe('needs_response')
        // Stripe states no stage code of its own, and the two spellings beside it are read from
        // Stripe's status by this package's own table — not by the harness, which no longer holds one.
        ->and($snapshot->stageCode)->toBeNull()
        ->and($snapshot->stage)->toBe('chargeback')
        ->and($snapshot->status)->toBe('needs_response')
        ->and($snapshot->disputedAmount?->equals(new Money(1500, new Currency('USD'))))->toBeTrue()
        // `due_by` is the last second of the day the network allows, so it reads as the day before
        // in UTC. Passed through verbatim rather than rounded up: rounding a deadline is a decision,
        // and rounding this one the wrong way is a missed response window.
        ->and($snapshot->responseDueAt?->format('Y-m-d H:i:s'))->toBe('2025-01-29 23:59:59');
});

it('records a dispute that arrived already lost, and the aggregate opens straight into that state', function () {
    // The trap the plan calls out. Stripe's API does not distinguish an unchallengeable dispute from
    // an ordinary one, so an already-decided case arrives on `charge.dispute.created` with no
    // earlier event to have missed — and a recorder that insisted on "created, therefore open" would
    // either throw on a case closed but never opened, or lose it.
    [$router, $recorder, , $references] = disputeContractHarness();

    $router->dispatch(new StoredWebhookCall(
        'stripe',
        GatewayId::generate(),
        disputeContractPayload('charge.dispute.created.already-lost.doc-sample.json'),
    ));

    $snapshot = $recorder->observed[0][2];

    expect($snapshot->statusCode)->toBe('lost')
        ->and($snapshot->stageCode)->toBeNull()
        ->and($snapshot->stage)->toBe('chargeback')
        ->and($snapshot->status)->toBe('lost');

    $aggregate = DisputeAggregate::open(disputeContractCommand(
        $snapshot,
        $recorder->observed[0][1],
        $recorder->disputeIds[$snapshot->gatewayDisputeRef],
    ));

    expect($aggregate->status())->toBe(DisputeStatus::Lost)
        ->and($aggregate->stage())->toBe(DisputeStage::Chargeback)
        // Nothing was normalised on the way through: the case is filed with its own answer, and it is
        // terminal from its first event. Stripe's own reference is not on the aggregate — it never
        // was a domain value — and the assertion that it survives the delivery now reads it where it
        // lives, in the reference table, keyed by the id the aggregate was opened under.
        ->and($references->findForDispute($aggregate->aggregateRootId()->toString()))->toBe('dp_1QkDisputeCaseGamma')
        // The provider's own date, through the snapshot and onto the aggregate untouched:
        // `evidence_details.due_by` is the instant, and nothing about it is normalised on the way.
        ->and($aggregate->deadlineAt()?->format('Y-m-d'))->toBe('2025-01-07');
});

it('reports a second dispute on one PaymentIntent as a second case with a second DisputeId', function () {
    // Nuvei and ConnexPay nest a re-escalation inside one case; Stripe does not. Two disputes raised
    // against one payment are two cases with two deadlines, two evidence packages and two outcomes,
    // and collapsing them into stages of one would book one of them against the other's window.
    [$router, $recorder, , $references] = disputeContractHarness();
    $gatewayId = GatewayId::generate();

    $router->dispatch(new StoredWebhookCall('stripe', $gatewayId, disputeContractPayload('charge.dispute.created.doc-sample.json')));
    $router->dispatch(new StoredWebhookCall('stripe', $gatewayId, disputeContractPayload('charge.dispute.created.second-case.doc-sample.json')));
    // The first case's event again, as a retry delivers it.
    $router->dispatch(new StoredWebhookCall('stripe', $gatewayId, disputeContractPayload('charge.dispute.created.doc-sample.json')));

    $reported = array_map(static fn (array $call): string => $call[2]->gatewayDisputeRef, $recorder->observed);

    expect($reported)->toBe(['dp_1QkDisputeCaseAlpha', 'dp_1QkDisputeCaseBeta', 'dp_1QkDisputeCaseAlpha'])
        // One payment, so one PaymentIntentId throughout — the cases are told apart by the
        // provider's reference and by nothing else.
        ->and(array_unique(array_map(static fn (array $call): string => $call[1], $recorder->observed)))
        ->toBe(['01929fa5-0000-7000-8000-0000000000b1']);

    $alpha = DisputeAggregate::open(disputeContractCommand(
        $recorder->observed[0][2],
        $recorder->observed[0][1],
        $recorder->disputeIds['dp_1QkDisputeCaseAlpha'],
    ));
    $beta = DisputeAggregate::open(disputeContractCommand(
        $recorder->observed[1][2],
        $recorder->observed[1][1],
        $recorder->disputeIds['dp_1QkDisputeCaseBeta'],
    ));

    expect($alpha->aggregateRootId()->toString())->not->toBe($beta->aggregateRootId()->toString())
        ->and($alpha->paymentIntentId()->equals($beta->paymentIntentId()))->toBeTrue()
        ->and($recorder->disputeIds['dp_1QkDisputeCaseAlpha']->toString())
        // The retry of Alpha's own event resolved to Alpha's own aggregate, and minted nothing new.
        ->toBe($alpha->aggregateRootId()->toString())
        ->and($recorder->disputeIds)->toHaveCount(2)
        // Two cases, two rows, each naming its own case at the provider — which is what makes the
        // second one addressable by its own port calls rather than by the first one's reference.
        ->and($references->findForDispute($alpha->aggregateRootId()->toString()))->toBe('dp_1QkDisputeCaseAlpha')
        ->and($references->findForDispute($beta->aggregateRootId()->toString()))->toBe('dp_1QkDisputeCaseBeta');
});

it('reports a decided case through the resolution call and an undecided one as a statement', function () {
    // `charge.dispute.closed` fires for both. Only one of them is an outcome, and only one of them
    // can be addressed by the case rather than by the payment.
    [$router, $recorder, , $references] = disputeContractHarness();
    $gatewayId = GatewayId::generate();

    $router->dispatch(new StoredWebhookCall('stripe', $gatewayId, disputeContractPayload('charge.dispute.closed.won.doc-sample.json')));
    $router->dispatch(new StoredWebhookCall('stripe', $gatewayId, disputeContractPayload('charge.dispute.closed.lost.doc-sample.json')));
    $router->dispatch(new StoredWebhookCall('stripe', $gatewayId, disputeContractPayload('charge.dispute.closed.warning-closed.doc-sample.json')));

    expect(array_map(static fn (array $call): array => [$call[1], $call[2]->statusCode], $recorder->resolved))
        ->toBe([
            ['dp_1QkDisputeCaseAlpha', 'won'],
            ['dp_1QkDisputeCaseGamma', 'lost'],
        ])
        ->and(array_map(static fn (array $call): array => [$call[2]->gatewayDisputeRef, $call[2]->statusCode], $recorder->observed))
        ->toBe([['dp_1QkDisputeCaseDelta', 'warning_closed']])
        // A resolution is addressed by the provider's own name and can be the first thing we ever hear
        // about the case — a backlog import, or a case raised outside any window we read — so the
        // resolution path writes the reference row too, under the same id the observed path would
        // have minted. Without this write, a case known only from a resolution could never be
        // conceded or answered afterwards.
        ->and($references->findForDispute($recorder->disputeIds['dp_1QkDisputeCaseAlpha']->toString()))
        ->toBe('dp_1QkDisputeCaseAlpha')
        ->and($references->findForDispute($recorder->disputeIds['dp_1QkDisputeCaseDelta']->toString()))
        ->toBe('dp_1QkDisputeCaseDelta');
});

it('has a fixture corpus that is the API\'s own dispute object, not a webhook-only shape', function () {
    // `GET /v1/disputes/:id` and the `data.object` of the events above are the same object, which is
    // what lets a case seen in the Dashboard be read the same way as one that arrived by webhook.
    // Asserted so the corpus cannot drift into a shape only the events have.
    $fromEvent = disputeContractPayload('charge.dispute.created.doc-sample.json')['data']['object'];
    $fromApi = disputeContractPayload('disputes.get.doc-sample.json');

    expect(array_keys($fromEvent))->toBe(array_keys($fromApi))
        ->and($fromEvent['id'])->toBe($fromApi['id'])
        ->and($fromEvent['object'])->toBe('dispute');
});
