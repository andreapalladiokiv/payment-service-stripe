<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\InstrumentReferenceEraser;
use Techork\PaymentService\Gateway\Webhook\Contract\StoredWebhookCall;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayCancellationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\RefundProcessingRecorder;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\Webhook\EventParser;
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
 * {@see StripeWebhookSubscriber} is the only place the gateway kind, the
 * verifier, the parser and eight handlers meet, and it was unexecuted. The real
 * {@see VerifierRegistry}, {@see HandlerRegistry} and {@see WebhookRouter} are
 * driven here rather than doubles, because every failure this class can have
 * lives BETWEEN the classes: a kind the registry is keyed by that the gateway
 * never reports, an event-type string that does not match what Stripe sends, a
 * handler constructor nobody can satisfy. Mocked registries would answer
 * whatever they were asked and prove none of it.
 *
 * Only the recorder/resolver layer stays mocked — that is the persistence
 * boundary, and the handlers have their own tests against it.
 *
 * The signing helper is re-declared under its own prefix rather than reusing
 * `signedRequest` from SignatureVerifierTest: Pest helpers are global, so
 * borrowing one would make this file pass only when the other is in the same run.
 */
function stripeWiringSecret(): string
{
    return 'whsec_'.bin2hex(random_bytes(8));
}

function stripeWiringCredential(string $secret): GatewayCredential
{
    return new readonly class($secret) implements GatewayCredential
    {
        public function __construct(private string $secret) {}

        public function getId(): GatewayId
        {
            return GatewayId::fromString('01929fa5-0000-7000-8000-0000000000f1');
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

/** A Stripe event envelope, the shape EventParser rebuilds an Event from. */
function stripeWiringPayload(string $type = 'payment_intent.canceled', string $objectId = 'pi_wire_1'): array
{
    return [
        'id' => 'evt_wire_1',
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => ['id' => $objectId, 'object' => 'payment_intent']],
    ];
}

/** A delivery signed the way Stripe signs one: `t=<ts>,v1=hmac(ts.body)`. */
function stripeWiringRequest(array $payload, string $secret): ServerRequestInterface
{
    $body = (string) json_encode($payload);
    $timestamp = time();
    $header = sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$body, $secret));

    $factory = new Psr17Factory;

    return $factory->createServerRequest('POST', 'https://merchant.example/webhooks/stripe')
        ->withHeader('Stripe-Signature', $header)
        ->withBody($factory->createStream($body))
        ->withParsedBody($payload);
}

/**
 * The subscriber with all eight handlers real and only the persistence boundary
 * mocked. Constructing them is itself part of what is pinned: the subscriber
 * names each by concrete type, so a handler whose constructor changed shape
 * fails here rather than at container-resolution time in production.
 */
function stripeWiringSubscriber(
    ?TransactionIdResolver $resolver = null,
    ?GatewayCancellationRecorder $cancellation = null,
): StripeWebhookSubscriber {
    $resolver ??= Mockery::mock(TransactionIdResolver::class);

    return new StripeWebhookSubscriber(
        new SignatureVerifier,
        new EventParser,
        new PaymentIntentSucceededHandler($resolver, Mockery::mock(GatewaySuccessRecorder::class)),
        new PaymentIntentCanceledHandler($resolver, $cancellation ?? Mockery::mock(GatewayCancellationRecorder::class)),
        new PaymentIntentFailedHandler($resolver, Mockery::mock(GatewayFailureRecorder::class)),
        new ChargeRefundedHandler($resolver, Mockery::mock(RefundProcessingRecorder::class)),
        new ChargeUpdatedHandler(
            $resolver,
            Mockery::mock(GatewayFeeRecorder::class),
            Mockery::mock(GatewayCredentialRepository::class),
        ),
        new ChargeRefundUpdatedHandler(
            $resolver,
            Mockery::mock(GatewayFeeRecorder::class),
            Mockery::mock(GatewayCredentialRepository::class),
        ),
        new PaymentMethodAttachedHandler(Mockery::mock(GatewayPaymentMethodRecorder::class)),
        new PaymentMethodDetachedHandler(Mockery::mock(InstrumentReferenceEraser::class)),
    );
}

/** Single-tenant repository, as the router's candidate iteration sees it. */
function stripeWiringRepository(GatewayCredential $credential): GatewayCredentialRepository
{
    return new readonly class($credential) implements GatewayCredentialRepository
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
}

/** @return array{VerifierRegistry, HandlerRegistry} */
function stripeWiringRegistries(?StripeWebhookSubscriber $subscriber = null): array
{
    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;

    ($subscriber ?? stripeWiringSubscriber())->subscribe($verifiers, $handlers);

    return [$verifiers, $handlers];
}

it('registers the verifier and parser under the kind the gateway reports', function () {
    // The router looks kinds up by GatewayCredential::getGatewayName(), which for
    // this package is StripeGateway::getName() = 'stripe', while the subscriber
    // registers the literal 'Stripe'. The registry lowercases both ends; pinned
    // because if the spellings stop meeting, every Stripe delivery resolves to no
    // verifier and is dropped.
    [$verifiers] = stripeWiringRegistries();

    $kind = new StripeGateway()->getName();

    expect($verifiers->hasKind($kind))->toBeTrue()
        ->and($verifiers->verifier($kind))->toBeInstanceOf(SignatureVerifier::class)
        ->and($verifiers->parser($kind))->toBeInstanceOf(EventParser::class);
});

it('points each subscribed Stripe event type at the handler written for it', function (string $eventType, string $handlerClass) {
    // These keys are Stripe's wire strings, written out as literals with no
    // constant to lean on — a typo in one is a whole lifecycle event silently
    // never handled. The two refund rows are the ones worth reading twice:
    // `charge.refunded` and `charge.refund.updated` differ by a single dot and
    // carry different objects.
    [, $handlers] = stripeWiringRegistries();

    expect($handlers->resolve('stripe', $eventType))->toBeInstanceOf($handlerClass);
})->with([
    'payment_intent.succeeded' => ['payment_intent.succeeded', PaymentIntentSucceededHandler::class],
    'payment_intent.canceled' => ['payment_intent.canceled', PaymentIntentCanceledHandler::class],
    'payment_intent.payment_failed' => ['payment_intent.payment_failed', PaymentIntentFailedHandler::class],
    'charge.refunded' => ['charge.refunded', ChargeRefundedHandler::class],
    'charge.updated' => ['charge.updated', ChargeUpdatedHandler::class],
    'charge.refund.updated' => ['charge.refund.updated', ChargeRefundUpdatedHandler::class],
    'payment_method.attached' => ['payment_method.attached', PaymentMethodAttachedHandler::class],
    'payment_method.detached' => ['payment_method.detached', PaymentMethodDetachedHandler::class],
]);

it('registers no handler for a Stripe event type we do not act on', function (string $eventType) {
    // Stripe delivers whatever the endpoint is subscribed to in the dashboard,
    // which is always more than this. Unmapped types must resolve to nothing so
    // the router reports Skipped.
    [, $handlers] = stripeWiringRegistries();

    expect($handlers->resolve('stripe', $eventType))->toBeNull();
})->with([
    'unsubscribed lifecycle event' => 'payment_intent.created',
    'a plausible near-miss spelling' => 'payment_intent.cancelled',
    'the British refund spelling' => 'charge.refund_updated',
    'no type at all' => '',
]);

it('identifies the tenant and the idempotency key from a signed delivery', function () {
    // End to end over the real router: HMAC verification against the credential,
    // kind resolution, and the Stripe event id as the idempotency key. This is the
    // path a live delivery takes before anything is stored.
    $secret = stripeWiringSecret();
    $credential = stripeWiringCredential($secret);

    [$verifiers, $handlers] = stripeWiringRegistries();
    $router = new WebhookRouter(stripeWiringRepository($credential), $verifiers, $handlers);

    $match = $router->identifyGateway(stripeWiringRequest(stripeWiringPayload(), $secret));

    expect($match)->not->toBeNull()
        ->and($match->kind)->toBe('stripe')
        ->and($match->externalId)->toBe('evt_wire_1')
        ->and($match->gatewayId->equals($credential->getId()))->toBeTrue();
});

it('identifies no tenant when the delivery is not signed for any candidate', function () {
    // The rejection has to survive the wiring: a forged delivery must leave
    // identifyGateway with null so nothing is stored under a tenant it does not
    // belong to.
    $credential = stripeWiringCredential(stripeWiringSecret());

    [$verifiers, $handlers] = stripeWiringRegistries();
    $router = new WebhookRouter(stripeWiringRepository($credential), $verifiers, $handlers);

    // Signed with a secret no configured tenant holds.
    $forged = stripeWiringRequest(stripeWiringPayload(), stripeWiringSecret());

    expect($router->identifyGateway($forged))->toBeNull();
});

it('dispatches a stored cancellation through the parser into its handler', function () {
    // The other half of the chain, from the stored record onwards: the parser
    // rebuilds a Stripe\Event and the handler reads data.object.id off it. A
    // mismatch between those two shapes would be invisible to per-class tests and
    // would leave every cancelled intent open.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $reference): bool => $reference === 'pi_wire_1')
        ->andReturn('01929fa5-0000-7000-8000-000000000009');

    $cancellation = Mockery::mock(GatewayCancellationRecorder::class);
    $cancellation->shouldReceive('onGatewayCancellation')->once()->andReturn(RecorderOutcome::Applied);

    [$verifiers, $handlers] = stripeWiringRegistries(stripeWiringSubscriber($resolver, $cancellation));

    $router = new WebhookRouter(
        stripeWiringRepository(stripeWiringCredential(stripeWiringSecret())),
        $verifiers,
        $handlers,
    );

    $outcome = $router->dispatch(new StoredWebhookCall('stripe', GatewayId::generate(), stripeWiringPayload()));

    expect($outcome)->toBe(HandlerOutcome::Processed);
});

it('skips a stored delivery whose event type has no registered handler', function () {
    // Unmapped types must come back Skipped — neither retried forever nor run
    // through a handler meant for something else.
    [$verifiers, $handlers] = stripeWiringRegistries();

    $router = new WebhookRouter(
        stripeWiringRepository(stripeWiringCredential(stripeWiringSecret())),
        $verifiers,
        $handlers,
    );

    expect($router->dispatch(new StoredWebhookCall(
        'stripe',
        GatewayId::generate(),
        stripeWiringPayload('payment_intent.created'),
    )))->toBe(HandlerOutcome::Skipped);
});
