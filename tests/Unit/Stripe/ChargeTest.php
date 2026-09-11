<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\Charge;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\StripeSettings;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;

function chargeStripeGateway(): StripeGateway
{
    $gw = new StripeGateway;
    $gw->configure(new GatewayInfrastructure(
        Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
        ['apiKey' => 'sk_test'],
    ));

    return $gw;
}

function chargeCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential
    {
        public function getId(): GatewayId
        {
            return GatewayId::generate();
        }

        public function getGatewayName(): string
        {
            return 'Stripe';
        }

        public function getCredentials(): array
        {
            return [];
        }
    };
}

function chargePassthroughDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface
    {
        public function decrypt(string $d): string
        {
            return $d;
        }
    };
}

function chargeTestCard(): CreditCard
{
    $enc = new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };

    return new CreditCard(
        Number::fromNumber('4242424242424242', $enc),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        Cvc::fromCvc('123', $enc),
    );
}

/**
 * The operation is built directly — see {@see stripeAuthorizeOperation()} for why. The customer
 * the gateway would resolve arrives as `customerReference`, unset unless a case is about it.
 *
 * @param  array<string, mixed>  $options
 */
function stripeChargeOperation(array $options): Charge
{
    $infrastructure = new GatewayInfrastructure(
        $options['gateway'] ?? Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        $options['decrypter'] ?? Mockery::mock(DecryptInterface::class),
        $options['referenceResolver'] ?? Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        $options['customerRepository'] ?? Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
    );

    return new Charge($infrastructure, $options['settings'] ?? new StripeSettings, new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: $options['instrument'] ?? Mockery::mock(PaymentInstrument::class),
        amount: $options['money'] ?? new Money(1000, new Currency('USD')),
        clientUniqueId: $options['clientUniqueId'] ?? null,
        threeDS: $options['threeDS'] ?? null,
        statementDescription: $options['statementDescription'] ?? null,
        description: $options['description'] ?? null,
        initiation: $options['initiation'] ?? PaymentInitiation::CardholderInitiated,
        customer: stripeSuiteCustomerFrom($options),
    ), $options['customerReference'] ?? null);
}

it('builds charge data for credit card', function () {
    $data = stripeChargeOperation([
        'money' => new Money(7500, new Currency('GBP')),
        'instrument' => chargeTestCard(),
        'gateway' => chargeCredential(),
        'decrypter' => chargePassthroughDecrypter(),
    ])->payload();

    expect($data['amount'])->toBe(7500)
        ->and($data['currency'])->toBe('gbp')
        ->and($data['payment_method_data']['type'])->toBe('card')
        ->and($data['payment_method_data']['card']['number'])->toBe('4242424242424242');
});

it('builds charge data for token', function () {
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('424242', '4242', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $ref = Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]);
    $ref->shouldReceive('find')->andReturn('tok_abc');

    $data = stripeChargeOperation([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => $token,
        'gateway' => chargeCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => $ref,
    ])->payload();

    expect($data['payment_method_data']['card']['token'])->toBe('tok_abc');
});

it('includes statement_descriptor_suffix when statementDescription is set', function () {
    $data = stripeChargeOperation([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => chargeTestCard(),
        'gateway' => chargeCredential(),
        'decrypter' => chargePassthroughDecrypter(),
        'statementDescription' => 'ACME Trip 42',
    ])->payload();

    // See the same assertion in AuthorizeTest — the bare key fails the call.
    expect($data['statement_descriptor_suffix'])->toBe('ACME Trip 42')
        ->and($data)->not->toHaveKey('statement_descriptor');
});

/**
 * The hosted branch is a different endpoint — a Checkout Session, not a PaymentIntent — so the
 * payload says which one it is and {@see Charge::charge()} dispatches on that. The amount and
 * currency still ride along, because the session's line item is built from them.
 */
it('builds hosted-checkout marker data for HostedPayment instrument', function () {
    $hosted = new HostedPayment(
        successUrl: 'https://merchant.example/success',
        cancelUrl: 'https://merchant.example/cancel',
    );

    $data = stripeChargeOperation([
        'money' => new Money(1500, new Currency('USD')),
        'instrument' => $hosted,
        'gateway' => chargeCredential(),
    ])->payload();

    expect($data['_hosted'])->toBeTrue()
        ->and($data['success_url'])->toBe('https://merchant.example/success')
        ->and($data['cancel_url'])->toBe('https://merchant.example/cancel')
        ->and($data['amount'])->toBe(1500)
        ->and($data['currency'])->toBe('usd');
});

it('refuses cash as a wiring error rather than letting it degrade into a decline', function () {
    stripeChargeOperation([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => new Cash,
        'gateway' => chargeCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
    ])->payload();
})->throws(UnsupportedInstrument::class, 'does not accept a "cash" instrument on the "purchase" operation');

/*
 * `it('stores threeDS parameter when provided')` and `it('has null threeDS parameter when not
 * provided')` lived here and are gone for the reason given at the foot of AuthorizeTest: they
 * read the attestation back out of omnipay's parameter bag, which no longer exists. What they
 * stood in for is asserted on the wire in ThreeDSIntegrationTest.
 */
// ─────────────────────────────────────────────────────────
//  A stored card charged to nobody
//
//  Attached is a STATE of a payment method rather than a second type, so "payable" is no longer
//  something a signature carries — each payment mapper checks it. That is the trade the shape
//  made, and this is its price: the refusal is asserted at every operation that makes it, because
//  a check one mapper forgets is a card charged to whoever it happened to be billed to, which is
//  the behaviour the customer split exists to end.
// ─────────────────────────────────────────────────────────

it('refuses to charge a stored card nobody has claimed', function () {
    $bare = new PaymentMethod(PaymentMethodId::generate(), testCard());

    expect(fn () => stripeChargeOperation([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => $bare,
        'referenceResolver' => fakeReferenceResolver('pm_xyz789'),
    ])->payload())->toThrow(UnsupportedInstrument::class, 'names no customer on the "charge" operation');
});

// ──────────────────────────────────────────────
//  charge() — the hosted branch on the wire
//
//  Nothing here reaches the network: the same static stub
//  {@see ApiRequestor::setHttpClient()} that CaptureTest uses answers
//  every call the `StripeClient` built inside `chargeHosted()` makes.
// ─────────────────────────────────────────────────────────

function stripeChargeFakeApi(array $body, int $status = 200): object
{
    $client = new class($body, $status) implements ClientInterface
    {
        /** @var list<array{method: string, url: string, headers: array, params: array}> */
        public array $calls = [];

        public function __construct(private array $body, private int $status) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->calls[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

            return [json_encode($this->body), $this->status, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

function stripeChargeSessionBody(): array
{
    return [
        'id' => 'cs_test_hosted_1',
        'object' => 'checkout_session',
        'payment_intent' => 'pi_hosted',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_hosted_1',
    ];
}

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

/**
 * The hosted branch creates a Checkout Session — a different Stripe endpoint
 * from the PaymentIntent branch above it — and Stripe saves the first result
 * made for any given idempotency key: a repeat with different parameters
 * errors (`idempotency_error`), one with identical parameters silently
 * replays the first session. A constant literal here would put every hosted
 * checkout under the account on a single key, so this pins that the key is
 * the caller's `clientUniqueId` scoped to the endpoint — the shape
 * {@see \Techork\PaymentService\Stripe\Concern\StripeRequestParameters::stripeOpts()}
 * builds for the registration calls — rather than a literal.
 */
it('sends the caller idempotency key scoped to the checkout-session endpoint', function () {
    $api = stripeChargeFakeApi(stripeChargeSessionBody());

    $result = stripeChargeOperation([
        'instrument' => new HostedPayment(
            successUrl: 'https://merchant.example/success',
            cancelUrl: 'https://merchant.example/cancel',
        ),
        'money' => new Money(1500, new Currency('USD')),
        'clientUniqueId' => 'checkout-uuid-9',
        'settings' => new StripeSettings('sk_test_fake'),
    ])->charge();

    expect($api->calls)->toHaveCount(1)
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/checkout/sessions')
        ->and($api->calls[0]['headers'])->toContain('Idempotency-Key: checkout-uuid-9:checkout_session')
        ->and($result->isRequiresAction())->toBeTrue()
        ->and($result->reference)->toBe('pi_hosted')
        ->and($result->challenge)->toBeInstanceOf(RedirectChallenge::class)
        ->and($result->challenge->transactionId)->toBe('cs_test_hosted_1')
        ->and($result->challenge->url)->toBe('https://checkout.stripe.com/c/pay/cs_test_hosted_1');
});
