<?php

declare(strict_types=1);

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Common\ValueObject\Challenge\SdkChallenge;
use Techork\PaymentService\Stripe\AuthorizeRequest;
use Techork\PaymentService\Stripe\CaptureRequest;
use Techork\PaymentService\Stripe\CreateCardRequest;
use Techork\PaymentService\Stripe\CreateCustomerRequest;
use Techork\PaymentService\Stripe\CreatePaymentMethodRequest;
use Techork\PaymentService\Stripe\PurchaseRequest;
use Techork\PaymentService\Stripe\RefundRequest;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\UpdateCustomerRequest;
use Techork\PaymentService\Stripe\VoidRequest;

function makeStripeGateway(): StripeGateway
{
    $gateway = new StripeGateway;
    $gateway->initialize(['apiKey' => 'sk_test_fake']);

    return $gateway;
}

it('has name stripe', function () {
    expect(makeStripeGateway()->getName())->toBe('stripe');
});

it('initializes with apiKey', function () {
    expect(makeStripeGateway()->getApiKey())->toBe('sk_test_fake');
});

it('creates createCustomer request', function () {
    expect(makeStripeGateway()->createCustomer())->toBeInstanceOf(CreateCustomerRequest::class);
});

it('creates updateCustomer request', function () {
    expect(makeStripeGateway()->updateCustomer())->toBeInstanceOf(UpdateCustomerRequest::class);
});

it('creates createCard request', function () {
    expect(makeStripeGateway()->createCard())->toBeInstanceOf(CreateCardRequest::class);
});

it('creates createPaymentMethod request', function () {
    expect(makeStripeGateway()->createPaymentMethod())->toBeInstanceOf(CreatePaymentMethodRequest::class);
});

it('creates purchase request', function () {
    expect(makeStripeGateway()->purchase())->toBeInstanceOf(PurchaseRequest::class);
});

it('creates authorize request', function () {
    expect(makeStripeGateway()->authorize())->toBeInstanceOf(AuthorizeRequest::class);
});

it('creates capture request', function () {
    expect(makeStripeGateway()->capture())->toBeInstanceOf(CaptureRequest::class);
});

it('creates refund request', function () {
    expect(makeStripeGateway()->refund())->toBeInstanceOf(RefundRequest::class);
});

it('creates void request', function () {
    expect(makeStripeGateway()->void())->toBeInstanceOf(VoidRequest::class);
});

// ──────────────────────────────────────────────
//  customer resolution on purchase/authorize
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

/**
 * Routes every Stripe SDK call to a canned JSON body. The afterEach below
 * restores the default curl client.
 */
function fakeStripeHttp(array $body, int $status = 200): void
{
    $client = new readonly class($body, $status) implements ClientInterface
    {
        public function __construct(private array $body, private int $status) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            return [json_encode($this->body), $this->status, []];
        }
    };

    ApiRequestor::setHttpClient($client);
}

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

/**
 * The six tests that used to sit here pinned the search that stood in for knowing who was
 * paying: keep an existing instrument-keyed link, adopt the owner from Stripe when the link was
 * missing, repair an empty-string link, fall through when the lookup failed, and create a
 * customer from a billing address. All of that machinery is gone — with a customer named,
 * resolution is one lookup and at most one creation, covered at the bottom of this file. The
 * behaviour was not moved; it was removed, deliberately and with the plan saying so.
 */


it('refuses card issuing with the marker that stops it becoming a decline', function (string $method) {
    // PaymentGatewayRouter rethrows only UnsupportedByGateway and folds everything else into
    // AuthorizationResult::failed(). These were a bare RuntimeException, so a card-issuing
    // request misrouted to Stripe was recorded as PaymentIntentFailed — an acquirer decline
    // for a request no acquirer saw. Stripe Issuing is a separate product: reaching these
    // means the wrong gateway, not a missing primitive.
    $thrown = null;

    try {
        (new StripeGateway)->{$method}();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class)
        ->and($thrown)->toBeInstanceOf(UnsupportedOperation::class);
})->with(['issueVirtualCard', 'updateVirtualCard', 'terminateVirtualCard']);

it('refuses an alternative-card refund WITHOUT the marker, so the refund can still fail gracefully', function () {
    // The deliberate asymmetry. Stripe refunds fine; only redirecting one onto another card is
    // absent, and PaymentGatewayRouter::refund relies on that falling through its catch as a
    // failed GatewayResult so the aggregate records RefundFailed and the saga carries on.
    // Marking it would rethrow instead and break step 2 of that method.
    $thrown = null;

    try {
        (new StripeGateway)->retryRefund();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown)->not->toBeInstanceOf(UnsupportedByGateway::class);
});

// ──────────────────────────────────────────────
//  requires_action is not an authorization
// ──────────────────────────────────────────────

function stripeAuthorizeAgainst(array $paymentIntent): \Omnipay\Common\Message\ResponseInterface
{
    fakeStripeHttp($paymentIntent);

    $encrypter = new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };
    $decrypter = new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };

    $gateway = makeStripeGateway();

    return $gateway->authorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => new CreditCard(
            Number::fromNumber('4000000000003184', $encrypter),
            Expiration::fromMonthAndYear(3, 2029),
            new Holder('John'),
            Cvc::fromCvc('321', $encrypter),
        ),
        'gateway' => stripeGatewayCredential(),
        'decrypter' => $decrypter,
    ])->send();
}

/**
 * The card is held only when Stripe says `requires_capture`. It answered
 * `requires_action` and the id was read as proof of an authorization, so the caller
 * booked money it did not have and found out at capture — by then the run that could
 * have sent the cardholder to their issuer was already over.
 */
it('does not report an authorization for a payment intent still owing an action', function () {
    $response = stripeAuthorizeAgainst([
        'id' => 'pi_needs_action',
        'object' => 'payment_intent',
        'status' => 'requires_action',
        'next_action' => ['type' => 'use_stripe_sdk', 'use_stripe_sdk' => ['type' => 'three_d_secure_redirect']],
    ]);

    expect($response->isSuccessful())->toBeFalse();
});

/**
 * An action shape this package has never seen is still a refusal, and it must say which
 * shape it was — the caller can only learn that if it is written down. `use_stripe_sdk` is
 * no longer one of these: it has its own challenge now.
 */
it('names an action shape it has never seen', function () {
    $response = stripeAuthorizeAgainst([
        'id' => 'pi_needs_action',
        'object' => 'payment_intent',
        'status' => 'requires_action',
        'next_action' => ['type' => 'verify_with_microdeposits', 'verify_with_microdeposits' => []],
    ]);

    expect($response->getChallenge())->toBeNull()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toContain('verify_with_microdeposits');
});

it('offers the step-up when Stripe hands back somewhere to send the cardholder', function () {
    $response = stripeAuthorizeAgainst([
        'id' => 'pi_redirecting',
        'object' => 'payment_intent',
        'status' => 'requires_action',
        'next_action' => [
            'type' => 'redirect_to_url',
            'redirect_to_url' => ['url' => 'https://hooks.stripe.com/3d_secure/authenticate', 'return_url' => 'https://merchant.example/back'],
        ],
    ]);

    expect($response->getChallenge())->not->toBeNull()
        ->and($response->getChallenge()->url)->toBe('https://hooks.stripe.com/3d_secure/authenticate');
});

it('reports an authorization once the money is actually held', function () {
    $response = stripeAuthorizeAgainst([
        'id' => 'pi_held',
        'object' => 'payment_intent',
        'status' => 'requires_capture',
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('pi_held');
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

    $encrypter = new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };

    // Through `initialize`, which is what GatewayFactory does with a credential's config —
    // the only route these settings take in production.
    $gateway = new StripeGateway;
    $gateway->initialize(['apiKey' => 'sk_test_fake', ...$credentials]);

    $gateway->authorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => new CreditCard(
            Number::fromNumber('4000000000003184', $encrypter),
            Expiration::fromMonthAndYear(3, 2029),
            new Holder('John'),
            Cvc::fromCvc('321', $encrypter),
        ),
        'gateway' => stripeGatewayCredential(),
        'decrypter' => new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } },
    ])->send();

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
 * The charge path was built the same way and broke the same way — `PurchaseRequest`
 * reported success from the presence of an id too.
 */
it('does not report a charge for a payment intent still owing an action', function () {
    fakeStripeHttp([
        'id' => 'pi_needs_action',
        'object' => 'payment_intent',
        'status' => 'requires_action',
        'next_action' => ['type' => 'use_stripe_sdk', 'use_stripe_sdk' => ['type' => 'three_d_secure_redirect']],
    ]);

    $encrypter = new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };

    $response = makeStripeGateway()->purchase([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => new CreditCard(
            Number::fromNumber('4000000000003184', $encrypter),
            Expiration::fromMonthAndYear(3, 2029),
            new Holder('John'),
            Cvc::fromCvc('321', $encrypter),
        ),
        'gateway' => stripeGatewayCredential(),
        'decrypter' => new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } },
    ])->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getChallenge())->toBeInstanceOf(SdkChallenge::class);
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

function stripeAuthorizeIntentWith(array $body, array $credentials): object
{
    fakeStripeHttp($body);

    $encrypter = new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };

    $gateway = new StripeGateway;
    $gateway->initialize(['apiKey' => 'sk_test_fake', ...$credentials]);

    return $gateway->authorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => new CreditCard(
            Number::fromNumber('4000002760003184', $encrypter),
            Expiration::fromMonthAndYear(3, 2029),
            new Holder('John'),
            Cvc::fromCvc('321', $encrypter),
        ),
        'gateway' => stripeGatewayCredential(),
        'decrypter' => new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } },
    ])->send();
}

/**
 * Stripe is the only gateway here that answers a 3DS card without an address — ConnexPay
 * returns `redirectUrl`, Nuvei `acsUrl`, Stripe says "run our JavaScript". Given a page
 * that does exactly that, the challenge comes back the same shape as everyone else's and
 * nothing downstream has to know Stripe has two.
 */
it('mints an address for the shape Stripe answers without one', function () {
    $response = stripeAuthorizeIntentWith(stripeSdkActionIntent(), [
        'authenticationUrl' => 'https://merchant.example/stripe/authenticate',
    ]);

    expect($response->getChallenge())->not->toBeNull()
        ->and($response->getChallenge()->url)->toBe('https://merchant.example/stripe/authenticate/pi_needs_sdk')
        // The protocol's own identifier, which Stripe does publish — better than the
        // intent id because the directory server keeps it too.
        ->and($response->getChallenge()->transactionId())->toBe('cb533804-6094-4944-8ac4-235c1bbf2c79')
        ->and($response->isSuccessful())->toBeFalse();
});

it('tolerates a trailing slash on the configured page', function () {
    $response = stripeAuthorizeIntentWith(stripeSdkActionIntent(), [
        'authenticationUrl' => 'https://merchant.example/stripe/authenticate/',
    ]);

    expect($response->getChallenge()->url)->toBe('https://merchant.example/stripe/authenticate/pi_needs_sdk');
});

/**
 * With no address configured the step-up is still describable, because this shape never
 * needed an address: the provider's SDK runs it in the payer's browser. It used to be
 * refused for want of a url it has no use for.
 */
it('describes the SDK shape without any address configured', function () {
    $response = stripeAuthorizeIntentWith(stripeSdkActionIntent(), []);

    $challenge = $response->getChallenge();

    expect($challenge)->toBeInstanceOf(SdkChallenge::class)
        ->and($challenge->authenticationId)->toBe('cb533804-6094-4944-8ac4-235c1bbf2c79')
        ->and($challenge->paymentReference)->toBe('pi_needs_sdk')
        // Still not an authorization: the money is not held until the payer answers.
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBeNull();
});

// ──────────────────────────────────────────────
//  told who is paying
// ──────────────────────────────────────────────

/**
 * Given a customer, resolution is a lookup. What it replaced was a search that stood in for
 * knowing: find by instrument, else ask Stripe who owns the instrument, else invent a customer
 * from whatever address rode along with this payment — so the same person paying from two
 * addresses became two Stripe customers. None of that survives, so there is nothing left to
 * assert it does not run.
 */
it('looks the customer up instead of searching for one', function () {
    $customerId = '0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff';

    $gatewayCustomers = Mockery::mock(GatewayCustomerRepository::class);
    $gatewayCustomers->shouldReceive('find')->once()->andReturn('cus_known');
    $gatewayCustomers->shouldNotReceive('saveReference');

    $gateway = makeStripeGateway();
    $gateway->setGatewayCustomerRepository($gatewayCustomers);

    $request = $gateway->authorize([
        'gateway' => stripeGatewayCredential(),
        'instrument' => stripeSavedPaymentMethod(),
        'customerId' => $customerId,
    ]);

    expect($request->getCustomerReference())->toBe('cus_known');
});

/**
 * A customer the map does not know is not created here — not on a payment and not on a
 * registration. `registerCustomer()` on the router is the operation that brings one into
 * existence, driven by whoever holds the customer, and `CreatePaymentMethodRequest` refuses
 * without a reference rather than inventing a person to attach the card to.
 */
it('never creates a customer, whatever the operation', function (string $method) {
    $gatewayCustomers = Mockery::mock(GatewayCustomerRepository::class);
    $gatewayCustomers->shouldReceive('find')->once()->andReturnNull();
    $gatewayCustomers->shouldNotReceive('saveReference');

    $gateway = makeStripeGateway();
    $gateway->setGatewayCustomerRepository($gatewayCustomers);

    $request = $gateway->{$method}([
        'gateway' => stripeGatewayCredential(),
        'instrument' => stripeSavedPaymentMethod(),
        'customerId' => '0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff',
    ]);

    expect($request->getCustomerReference())->toBe('');
})->with(['authorize', 'purchase', 'createPaymentMethod']);

/**
 * No identity source bound means nothing to build a customer from, and null is the honest
 * answer. It surfaces as a refused registration rather than as a Stripe customer named after one
 * payment's billing details.
 */
it('declines to invent a customer when it cannot be told who they are', function () {
    $gatewayCustomers = Mockery::mock(GatewayCustomerRepository::class);
    $gatewayCustomers->shouldReceive('find')->once()->andReturnNull();
    $gatewayCustomers->shouldNotReceive('saveReference');

    $gateway = makeStripeGateway();
    $gateway->setGatewayCustomerRepository($gatewayCustomers);

    $request = $gateway->authorize([
        'gateway' => stripeGatewayCredential(),
        'instrument' => stripeSavedPaymentMethod(),
        'customerId' => '0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff',
    ]);

    expect($request->getCustomerReference())->toBe('');
});
