<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Stripe\Authorize;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\StripeSettings;

function stripeGateway(): StripeGateway
{
    $gw = new StripeGateway;
    $gw->configure(new GatewayInfrastructure(
        Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
        ['apiKey' => 'sk_test_fake'],
    ));

    return $gw;
}

function fakeDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface
    {
        public function decrypt(string $data): string { return $data; }
    };
}

function fakeCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential
    {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'Stripe'; }
        public function getCredentials(): array { return []; }
    };
}

function fakeReferenceResolver(string $reference): GatewayInstrumentRepository
{
    $mock = Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]);
    $mock->shouldReceive('find')->andReturn($reference);

    return $mock;
}

/**
 * A stored instrument makes the gateway ask Stripe who owns it, so the two payment-method tests
 * below would reach the network without this. The stub answers "nobody", which is the branch that
 * leaves the payload's `customer` unset — and unset is what those tests are about.
 */
function stripeAuthorizeFakeHttp(array $body, int $status = 200): void
{
    ApiRequestor::setHttpClient(new readonly class($body, $status) implements ClientInterface
    {
        public function __construct(private array $body, private int $status) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            return [json_encode($this->body), $this->status, []];
        }
    });
}

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

function testCard(): CreditCard
{
    return new CreditCard(
        Number::fromNumber('4242424242424242', new class implements EncryptInterface {
            public function encrypt(string $data): string { return $data; }
        }),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('123', new class implements EncryptInterface {
            public function encrypt(string $data): string { return $data; }
        }),
    );
}

/**
 * The operation is built directly rather than reached through the gateway. It used to be handed
 * back by an `authorizing()` accessor, which existed only so a payload could be read before the
 * call went out; the gateway authorizes now, and the two arguments it would have supplied — the
 * infrastructure and the customer it resolved for the instrument — are the two this takes. That
 * the gateway resolves the customer is pinned where it happens, in StripeGatewayTest.
 *
 * @param  array<string, mixed>  $options
 */
function stripeAuthorizeOperation(array $options): Authorize
{
    $infrastructure = new GatewayInfrastructure(
        $options['gateway'] ?? Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        $options['decrypter'] ?? Mockery::mock(DecryptInterface::class),
        $options['referenceResolver'] ?? Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        $options['customerRepository'] ?? Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
        ['apiKey' => 'sk_test_fake'],
    );

    return new Authorize($infrastructure, new StripeSettings('sk_test_fake'), new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: $options['instrument'] ?? Mockery::mock(PaymentInstrument::class),
        amount: $options['money'] ?? new Money(1000, new Currency('USD')),
        clientUniqueId: $options['clientUniqueId'] ?? null,
        billingAddress: $options['billingAddress'] ?? null,
        threeDS: $options['threeDS'] ?? null,
        statementDescription: $options['statementDescription'] ?? null,
        description: $options['description'] ?? null,
        initiation: $options['initiation'] ?? PaymentInitiation::CardholderInitiated,
    ), $options['customerReference'] ?? null);
}

it('builds authorize data for credit card', function () {
    $data = stripeAuthorizeOperation([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => testCard(),
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
    ])->payload();

    expect($data['amount'])->toBe(5000)
        ->and($data['currency'])->toBe('usd')
        ->and($data['payment_method_data']['type'])->toBe('card')
        ->and($data['payment_method_data']['card']['number'])->toBe('4242424242424242')
        ->and($data['payment_method_data']['card']['exp_month'])->toBe(12)
        ->and($data['payment_method_data']['card']['exp_year'])->toBe(2030)
        ->and($data['payment_method_data']['card']['cvc'])->toBe('123')
        ->and($data)->not->toHaveKey('customer');
});

it('builds authorize data for token', function () {
    $token = new Token(
        TokenId::generate(),
        testCard(),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $data = stripeAuthorizeOperation([
        'money' => new Money(3000, new Currency('EUR')),
        'instrument' => $token,
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'referenceResolver' => fakeReferenceResolver('tok_abc123'),
    ])->payload();

    expect($data['amount'])->toBe(3000)
        ->and($data['currency'])->toBe('eur')
        ->and($data['payment_method_data']['card']['token'])->toBe('tok_abc123');
});

it('builds authorize data for payment method with pm_ reference', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        testCard(),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    stripeAuthorizeFakeHttp(['id' => 'pm_x', 'object' => 'payment_method', 'customer' => null]);

    $data = stripeAuthorizeOperation([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => $pm,
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'referenceResolver' => fakeReferenceResolver('pm_xyz789'),
    ])->payload();

    expect($data['payment_method'])->toBe('pm_xyz789')
        ->and($data)->not->toHaveKey('payment_method_data');
});

it('builds authorize data for payment method with tok_ reference', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        testCard(),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    stripeAuthorizeFakeHttp(['id' => 'pm_x', 'object' => 'payment_method', 'customer' => null]);

    $data = stripeAuthorizeOperation([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => $pm,
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'referenceResolver' => fakeReferenceResolver('tok_legacy123'),
    ])->payload();

    expect($data['payment_method'])->toBe('tok_legacy123')
        ->and($data)->not->toHaveKey('payment_method_data');
});

/**
 * The customer is resolved by the gateway from the instrument's owner, not handed in with the
 * payment. It used to be possible to pass one through the options array; a
 * {@see \Techork\PaymentService\Gateway\Command\PlacementCommand} has no slot for it, which is
 * the point — a payment names an instrument, and who that instrument belongs to is a fact to look
 * up rather than one for the caller to assert.
 */
it('includes the customer reference the gateway resolved for the instrument', function () {
    $sent = fakeStripeHttp(['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'requires_capture']);

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn('cus_abc');

    $gateway = stripeGateway();
    $gateway->configure(new GatewayInfrastructure(
        fakeCredential(),
        fakeDecrypter(),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        $customers,
        ['apiKey' => 'sk_test_fake'],
    ));

    $gateway->authorize(new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: testCard(),
        amount: new Money(5000, new Currency('USD')),
    ));

    // Through the intent Stripe received: the resolved reference is a constructor argument on the
    // operation and never a property of the command, so the wire is where it surfaces.
    expect(lastStripeRequestTo($sent, 'payment_intents')['customer'])->toBe('cus_abc');
});

afterEach(function () {
    Stripe\ApiRequestor::setHttpClient(Stripe\HttpClient\CurlClient::instance());
});

it('includes statement_descriptor_suffix when statementDescription is set', function () {
    $data = stripeAuthorizeOperation([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => testCard(),
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'statementDescription' => 'ACME Trip 42',
    ])->payload();

    // The bare `statement_descriptor` must not come back: Stripe rejects the whole
    // PaymentIntent for a card when it is present, so a revert here fails every payment
    // carrying a descriptor rather than degrading what shows on the statement.
    expect($data['statement_descriptor_suffix'])->toBe('ACME Trip 42')
        ->and($data)->not->toHaveKey('statement_descriptor');
});

it('omits statement_descriptor_suffix when statementDescription is null or empty', function () {
    $data = stripeAuthorizeOperation([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => testCard(),
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'statementDescription' => '',
    ])->payload();

    expect($data)->not->toHaveKey('statement_descriptor_suffix');
});

it('throws on cash instrument', function () {
    stripeAuthorizeOperation([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => new Cash,
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
    ])->payload();
})->throws(UnsupportedInstrument::class, 'does not accept a "cash" instrument on the "authorize" operation');

/*
 * Two tests lived here — `it('stores threeDS parameter when provided')` and `it('has null threeDS
 * parameter when not provided')` — and both asked the request to hand back the attestation it had
 * been given, through the `getThreeDS()` accessor omnipay's parameter bag supplied. There is no
 * bag and no accessor: the attestation is a field of {@see PlacementCommand}, so a round-trip
 * through the operation would only be asserting that a readonly property kept its value.
 *
 * What the round-trip was standing in for — that the attestation reaches Stripe — is asserted
 * directly in ThreeDSIntegrationTest, on the params the SDK puts on the wire.
 */
