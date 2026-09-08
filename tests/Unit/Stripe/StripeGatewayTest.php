<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Challenge\SdkChallenge;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\Authorize;
use Techork\PaymentService\Stripe\Cancel;
use Techork\PaymentService\Stripe\Capture;
use Techork\PaymentService\Stripe\Charge;
use Techork\PaymentService\Stripe\CreateCustomer;
use Techork\PaymentService\Stripe\Refund;
use Techork\PaymentService\Stripe\RegisterPaymentMethod;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\StripeSettings;
use Techork\PaymentService\Stripe\Tokenize;
use Techork\PaymentService\Stripe\UpdateCustomer;

function stripeGatewayInfrastructure(): GatewayInfrastructure
{
    return new GatewayInfrastructure(
        Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
        ['apiKey' => 'sk_test_fake'],
    );
}

function makeStripeGateway(): StripeGateway
{
    $gateway = new StripeGateway;
    $gateway->configure(stripeGatewayInfrastructure());

    return $gateway;
}

it('has name stripe', function () {
    expect(makeStripeGateway()->getName())->toBe('stripe');
});

it('initializes with apiKey', function () {
    expect(makeStripeGateway()->getApiKey())->toBe('sk_test_fake');
});

function stripeGatewayPlacement(): PlacementCommand
{
    return new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
        amount: new Money(1000, new Currency('USD')),
    );
}

function stripeGatewayVault(): VaultCommand
{
    return new VaultCommand(
        gatewayId: GatewayId::generate(),
        instrument: new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
    );
}

/*
 * Eight tests asserting that the gateway hands back a `Tokenize`, a `RegisterPaymentMethod`, a
 * `Charge`, an `Authorize`, a `Capture` and a `Refund` lived here. They went with the accessors
 * they called: the gateway performs an operation and answers with a result, so which class it
 * built on the way is a return type PHP checks, not a fact worth a test. What is worth pinning —
 * the payload each builds and the result it maps — lives in that operation's own file.
 */

it('places a rebilling payment through the authorize operation', function () {
    $rebilling = new RebillingCommand(
        gatewayId: GatewayId::generate(),
        instrument: new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
        amount: new Money(4200, new Currency('USD')),
        initiation: PaymentInitiation::MerchantRecurring,
        genesisReference: 'pi_genesis',
    );

    // Through the operation the gateway would build, rather than through the gateway: what is
    // pinned is that a series payment maps onto an ordinary placement and keeps its own fields.
    $operation = new Authorize(
        stripeGatewayInfrastructure(),
        new StripeSettings('sk_test_fake'),
        $rebilling->toPlacement(),
    );

    expect($operation->payload()['amount'])->toBe(4200);
});

// ──────────────────────────────────────────────
//  customer resolution on charge / registration
// ──────────────────────────────────────────────

function stripeGatewayCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential
    {
        public function getId(): GatewayId
        {
            return GatewayId::generate();
        }

        public function getGatewayName(): string
        {
            return 'stripe';
        }

        public function getCredentials(): array
        {
            return [];
        }
    };
}

function stripeSavedPaymentMethod(): PaymentMethod
{
    return new PaymentMethod(
        PaymentMethodId::generate(),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );
}

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

/**
 * The gateway resolves the customer and hands it to the operation, which sends it as the intent's
 * `customer` — so the charge Stripe received is where the resolution becomes observable. It used
 * to be read off the request through a `getCustomerReference()` accessor over the parameter bag,
 * then off the built operation's payload; there is neither a bag nor an unperformed operation to
 * ask, because `charge()` charges.
 *
 * The caller has already installed the fake transport and holds its recording; the canned body it
 * set answers the payment-method lookup under test, and answers the intent creation too. That the
 * intent then maps to a failed result is beside the point here — nothing reads the result.
 *
 * @param  array<string, mixed>  $options
 * @param  ArrayObject<int, array{url: string, params: array<string, mixed>}>  $sent
 */
function stripeResolvedCustomerFor(StripeGateway $gateway, ArrayObject $sent, array $options): ?string
{
    $gateway->configure(new GatewayInfrastructure(
        $options['gateway'] ?? stripeGatewayCredential(),
        Mockery::mock(DecryptInterface::class),
        // Answers by default, because the customer is read off the payload and building one
        // visits the instrument — a stored PaymentMethod with no reference cannot be charged at
        // all, which is a different refusal from the one under test.
        $options['referenceResolver'] ?? Mockery::mock(GatewayInstrumentRepository::class, ['find' => 'pm_123']),
        $options['customerRepository'] ?? Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
        ['apiKey' => 'sk_test_fake'],
    ));
    $gateway->setCustomerRepository($options['customerRepository']);

    $command = new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: $options['instrument'],
        amount: new Money(1000, new Currency('USD')),
        billingAddress: $options['billingAddress'] ?? null,
    );

    $gateway->charge($command);

    $customer = lastStripeRequestTo($sent, 'payment_intents')['customer'] ?? null;

    return is_string($customer) ? $customer : null;
}

it('keeps an existing non-empty customer link on charge', function () {
    $sent = fakeStripeHttp(['id' => 'pm_123', 'object' => 'payment_method', 'customer' => 'cus_existing'], 200, ['payment_intents' => ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]);

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn('cus_existing');
    $customers->shouldNotReceive('saveAndAttach');

    expect(stripeResolvedCustomerFor(makeStripeGateway(), $sent, [
        'instrument' => stripeSavedPaymentMethod(),
        'customerRepository' => $customers,
    ]))->toBe('cus_existing');
});

it('adopts the owning customer from Stripe when the local link is missing', function () {
    $sent = fakeStripeHttp(['id' => 'pm_123', 'object' => 'payment_method', 'customer' => 'cus_owner'], 200, ['payment_intents' => ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]);

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn(null);
    $customers->shouldReceive('saveAndAttach')
        ->once()
        ->withArgs(fn ($gatewayId, $instrument, $reference) => $reference === 'cus_owner');

    $resolver = Mockery::mock(GatewayInstrumentRepository::class);
    $resolver->shouldReceive('find')->andReturn('pm_123');

    expect(stripeResolvedCustomerFor(makeStripeGateway(), $sent, [
        'instrument' => stripeSavedPaymentMethod(),
        'customerRepository' => $customers,
        'referenceResolver' => $resolver,
    ]))->toBe('cus_owner');
});

it('treats an empty-string customer link as missing and repairs it from Stripe', function () {
    $sent = fakeStripeHttp(['id' => 'pm_123', 'object' => 'payment_method', 'customer' => 'cus_owner'], 200, ['payment_intents' => ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]);

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn('');
    $customers->shouldReceive('saveAndAttach')->once();

    $resolver = Mockery::mock(GatewayInstrumentRepository::class);
    $resolver->shouldReceive('find')->andReturn('pm_123');

    expect(stripeResolvedCustomerFor(makeStripeGateway(), $sent, [
        'instrument' => stripeSavedPaymentMethod(),
        'customerRepository' => $customers,
        'referenceResolver' => $resolver,
    ]))->toBe('cus_owner');
});

it('leaves the customer unset when Stripe reports the payment method has no owner', function () {
    $sent = fakeStripeHttp(['id' => 'pm_123', 'object' => 'payment_method', 'customer' => null], 200, ['payment_intents' => ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]);

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn(null);
    $customers->shouldNotReceive('saveAndAttach');

    $resolver = Mockery::mock(GatewayInstrumentRepository::class);
    $resolver->shouldReceive('find')->andReturn('pm_123');

    // No billing address on the command either, so no customer is created.
    expect(stripeResolvedCustomerFor(makeStripeGateway(), $sent, [
        'instrument' => stripeSavedPaymentMethod(),
        'customerRepository' => $customers,
        'referenceResolver' => $resolver,
    ]))->toBeNull();
});

/**
 * Email is optional on a {@see BillingAddress}, and it used to decide whether the
 * instrument got a Stripe Customer at all. A PaymentMethod with no Customer is
 * single-use — the SetupIntent confirm spends it, and Stripe refuses every later
 * charge — so an address without an email registered a card nobody could use.
 */
it('creates a customer from a billing address that carries no email', function () {
    $sent = fakeStripeHttp(['id' => 'cus_no_email', 'object' => 'customer'], 200, [
        'payment_methods' => ['id' => 'pm_new', 'object' => 'payment_method'],
        'setup_intents' => ['id' => 'seti_1', 'object' => 'setup_intent', 'status' => 'succeeded'],
    ]);

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn(null);
    $customers->shouldReceive('saveAndAttach')->once();

    $gateway = makeStripeGateway();
    $gateway->configure(new GatewayInfrastructure(
        stripeGatewayCredential(),
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        $customers,
        ['apiKey' => 'sk_test_fake'],
    ));

    $gateway->registerPaymentMethod(new VaultCommand(
        gatewayId: GatewayId::generate(),
        instrument: new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
        billingAddress: new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    ));

    // The resolved customer reaches the operation as a constructor argument, and the attach call
    // attach is where it becomes visible — it is also the value the operation refuses on when it is empty.
    expect(lastStripeRequestTo($sent, 'attach')['customer'] ?? null)->toBe('cus_no_email');
});

it('falls through gracefully when the Stripe lookup fails', function () {
    $sent = fakeStripeHttp(['error' => ['type' => 'invalid_request_error', 'message' => 'No such payment method']], 404, ['payment_intents' => ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]);

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn(null);
    $customers->shouldNotReceive('saveAndAttach');

    $resolver = Mockery::mock(GatewayInstrumentRepository::class);
    $resolver->shouldReceive('find')->andReturn('pm_gone');

    expect(stripeResolvedCustomerFor(makeStripeGateway(), $sent, [
        'instrument' => stripeSavedPaymentMethod(),
        'customerRepository' => $customers,
        'referenceResolver' => $resolver,
    ]))->toBeNull();
});

// ──────────────────────────────────────────────
//  Refusals, and which of them may be folded into a decline
// ──────────────────────────────────────────────

it('refuses card issuing with the marker that stops it becoming a decline', function (callable $call) {
    // The router rethrows only UnsupportedByGateway and folds everything else into
    // AuthorizationResult::failed(). These were a bare RuntimeException, so a card-issuing
    // request misrouted to Stripe was recorded as PaymentIntentFailed — an acquirer decline
    // for a request no acquirer saw. Stripe Issuing is a separate product: reaching these
    // means the wrong gateway, not a missing primitive.
    $thrown = null;

    try {
        $call(new StripeGateway);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class)
        ->and($thrown)->toBeInstanceOf(UnsupportedOperation::class);
})->with([
    'issueVirtualCard' => [fn (StripeGateway $g) => $g->issueVirtualCard(new IssueCardCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'pi_1',
        amountLimit: new Money(1000, new Currency('USD')),
        spendCategory: CardSpendCategory::TravelAir,
    ))],
    'updateVirtualCard' => [fn (StripeGateway $g) => $g->updateVirtualCard(new UpdateCardCommand(
        gatewayId: GatewayId::generate(),
        cardGuid: 'card_1',
        amountLimit: new Money(1000, new Currency('USD')),
        spendCategory: CardSpendCategory::TravelAir,
    ))],
    'terminateVirtualCard' => [fn (StripeGateway $g) => $g->terminateVirtualCard(
        new TerminateCardCommand(GatewayId::generate(), 'card_1'),
    )],
]);

it('refuses an alternative-card refund WITHOUT the marker, so the refund can still fail gracefully', function () {
    // The deliberate asymmetry. Stripe refunds fine; only redirecting one onto another card is
    // absent, and the refund path relies on that falling through its catch as a failed
    // GatewayResult so the aggregate records RefundFailed and the saga carries on. Marking it
    // would rethrow instead and break step 2 of that method.
    $thrown = null;

    try {
        new StripeGateway()->retryRefund(new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'pi_1',
            amount: new Money(1000, new Currency('USD')),
        ));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown)->not->toBeInstanceOf(UnsupportedByGateway::class);
});

// ──────────────────────────────────────────────
//  requires_action is not an authorization
// ──────────────────────────────────────────────

function stripeAuthorizeAgainst(array $paymentIntent, array $credentials = []): AuthorizationResult
{
    fakeStripeHttp($paymentIntent);

    $encrypter = new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };

    // Through `configure`, which is what GatewayFactory does with a credential's settings —
    // the only route these take in production.
    $gateway = new StripeGateway;
    $gateway->configure(new GatewayInfrastructure(
        stripeGatewayCredential(),
        new class implements DecryptInterface
        {
            public function decrypt(string $d): string
            {
                return $d;
            }
        },
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
        ['apiKey' => 'sk_test_fake', ...$credentials],
    ));

    return $gateway->authorize(new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: new CreditCard(
            Number::fromNumber('4000000000003184', $encrypter),
            Expiration::fromMonthAndYear(3, 2029),
            new Holder('John'),
            Cvc::fromCvc('321', $encrypter),
        ),
        amount: new Money(5000, new Currency('USD')),
    ));
}

/**
 * The card is held only when Stripe says `requires_capture`. It answered
 * `requires_action` and the id was read as proof of an authorization, so the caller
 * booked money it did not have and found out at capture — by then the run that could
 * have sent the cardholder to their issuer was already over.
 */
it('does not report an authorization for a payment intent still owing an action', function () {
    $result = stripeAuthorizeAgainst([
        'id' => 'pi_needs_action',
        'object' => 'payment_intent',
        'status' => 'requires_action',
        'next_action' => ['type' => 'use_stripe_sdk', 'use_stripe_sdk' => ['type' => 'three_d_secure_redirect']],
    ]);

    expect($result->success)->toBeFalse();
});

/**
 * An action shape this package has never seen is still a refusal, and it must say which
 * shape it was — the caller can only learn that if it is written down. `use_stripe_sdk` is
 * no longer one of these: it has its own challenge now.
 */
it('names an action shape it has never seen', function () {
    $result = stripeAuthorizeAgainst([
        'id' => 'pi_needs_action',
        'object' => 'payment_intent',
        'status' => 'requires_action',
        'next_action' => ['type' => 'verify_with_microdeposits', 'verify_with_microdeposits' => []],
    ]);

    expect($result->challenge)->toBeNull()
        ->and($result->success)->toBeFalse()
        ->and($result->message)->toContain('verify_with_microdeposits');
});

it('offers the step-up when Stripe hands back somewhere to send the cardholder', function () {
    $result = stripeAuthorizeAgainst([
        'id' => 'pi_redirecting',
        'object' => 'payment_intent',
        'status' => 'requires_action',
        'next_action' => [
            'type' => 'redirect_to_url',
            'redirect_to_url' => ['url' => 'https://hooks.stripe.com/3d_secure/authenticate', 'return_url' => 'https://merchant.example/back'],
        ],
    ]);

    expect($result->challenge)->not->toBeNull()
        ->and($result->challenge->url)->toBe('https://hooks.stripe.com/3d_secure/authenticate');
});

it('reports an authorization once the money is actually held', function () {
    $result = stripeAuthorizeAgainst([
        'id' => 'pi_held',
        'object' => 'payment_intent',
        'status' => 'requires_capture',
    ]);

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('pi_held');
});

/**
 * Records what was actually sent to Stripe, which is the only way to tell a parameter
 * that was built from one that was dropped on the way.
 */
function recordingStripeHttp(array $body): object
{
    $recorder = new class
    {
        public array $params = [];
    };

    ApiRequestor::setHttpClient(new readonly class($body, $recorder) implements ClientInterface
    {
        public function __construct(private array $body, private object $recorder) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->recorder->params = is_array($params) ? $params : [];

            return [json_encode($this->body), 200, []];
        }
    });

    return $recorder;
}

function stripeAuthorizeWith(array $credentials): object
{
    $recorder = recordingStripeHttp(['id' => 'pi_x', 'object' => 'payment_intent', 'status' => 'requires_capture']);

    $encrypter = new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };

    $gateway = new StripeGateway;
    $gateway->configure(new GatewayInfrastructure(
        stripeGatewayCredential(),
        new class implements DecryptInterface
        {
            public function decrypt(string $d): string
            {
                return $d;
            }
        },
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
        ['apiKey' => 'sk_test_fake', ...$credentials],
    ));

    $gateway->authorize(new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: new CreditCard(
            Number::fromNumber('4000000000003184', $encrypter),
            Expiration::fromMonthAndYear(3, 2029),
            new Holder('John'),
            Cvc::fromCvc('321', $encrypter),
        ),
        amount: new Money(5000, new Currency('USD')),
    ));

    return $recorder;
}

/**
 * Refusing redirects is what left Stripe with only `use_stripe_sdk` to offer a card owing
 * 3DS. Given somewhere to come back to, it may answer with an address instead — which is
 * the one shape this package can put in front of a cardholder.
 */
it('lets Stripe answer with a redirect once the caller says where to come back to', function () {
    $recorder = stripeAuthorizeWith(['returnUrl' => 'https://merchant.example/checkout/back']);

    expect($recorder->params['return_url'])->toBe('https://merchant.example/checkout/back')
        ->and($recorder->params['automatic_payment_methods']['allow_redirects'])->toBe('always');
});

it('refuses redirects when the caller named nowhere to return to', function () {
    $recorder = stripeAuthorizeWith([]);

    expect($recorder->params)->not->toHaveKey('return_url')
        ->and($recorder->params['automatic_payment_methods']['allow_redirects'])->toBe('never');
});

/**
 * The charge path was built the same way and broke the same way — it reported success
 * from the presence of an id too.
 */
it('does not report a charge for a payment intent still owing an action', function () {
    fakeStripeHttp([
        'id' => 'pi_needs_action',
        'object' => 'payment_intent',
        'status' => 'requires_action',
        'next_action' => ['type' => 'use_stripe_sdk', 'use_stripe_sdk' => ['type' => 'three_d_secure_redirect']],
    ]);

    $encrypter = new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };

    $gateway = new StripeGateway;
    $gateway->configure(new GatewayInfrastructure(
        stripeGatewayCredential(),
        new class implements DecryptInterface
        {
            public function decrypt(string $d): string
            {
                return $d;
            }
        },
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
        ['apiKey' => 'sk_test_fake'],
    ));

    $result = $gateway->charge(new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: new CreditCard(
            Number::fromNumber('4000000000003184', $encrypter),
            Expiration::fromMonthAndYear(3, 2029),
            new Holder('John'),
            Cvc::fromCvc('321', $encrypter),
        ),
        amount: new Money(5000, new Currency('USD')),
    ));

    expect($result->success)->toBeFalse()
        ->and($result->challenge)->toBeInstanceOf(SdkChallenge::class);
});

// ──────────────────────────────────────────────
//  the SDK shape becomes an address like everyone else's
// ──────────────────────────────────────────────

function stripeSdkActionIntent(): array
{
    return [
        'id' => 'pi_needs_sdk',
        'object' => 'payment_intent',
        'status' => 'requires_action',
        'next_action' => [
            'type' => 'use_stripe_sdk',
            'use_stripe_sdk' => [
                'type' => 'stripe_3ds2_fingerprint',
                'server_transaction_id' => 'cb533804-6094-4944-8ac4-235c1bbf2c79',
                'directory_server_name' => 'visa',
            ],
        ],
    ];
}

/**
 * Stripe is the only gateway here that answers a 3DS card without an address — ConnexPay
 * returns `redirectUrl`, Nuvei `acsUrl`, Stripe says "run our JavaScript". Given a page
 * that does exactly that, the challenge comes back the same shape as everyone else's and
 * nothing downstream has to know Stripe has two.
 */
it('mints an address for the shape Stripe answers without one', function () {
    $result = stripeAuthorizeAgainst(stripeSdkActionIntent(), [
        'authenticationUrl' => 'https://merchant.example/stripe/authenticate',
    ]);

    expect($result->challenge)->not->toBeNull()
        ->and($result->challenge->url)->toBe('https://merchant.example/stripe/authenticate/pi_needs_sdk')
        // The protocol's own identifier, which Stripe does publish — better than the
        // intent id because the directory server keeps it too.
        ->and($result->challenge->transactionId())->toBe('cb533804-6094-4944-8ac4-235c1bbf2c79')
        ->and($result->success)->toBeFalse();
});

it('tolerates a trailing slash on the configured page', function () {
    $result = stripeAuthorizeAgainst(stripeSdkActionIntent(), [
        'authenticationUrl' => 'https://merchant.example/stripe/authenticate/',
    ]);

    expect($result->challenge->url)->toBe('https://merchant.example/stripe/authenticate/pi_needs_sdk');
});

/**
 * With no address configured the step-up is still describable, because this shape never
 * needed an address: the provider's SDK runs it in the payer's browser. It used to be
 * refused for want of a url it has no use for.
 */
it('describes the SDK shape without any address configured', function () {
    $result = stripeAuthorizeAgainst(stripeSdkActionIntent());

    expect($result->challenge)->toBeInstanceOf(SdkChallenge::class)
        ->and($result->challenge->authenticationId)->toBe('cb533804-6094-4944-8ac4-235c1bbf2c79')
        ->and($result->challenge->paymentReference)->toBe('pi_needs_sdk')
        // Still not an authorization: the money is not held until the payer answers.
        ->and($result->success)->toBeFalse()
        ->and($result->message)->toBeNull();
});
