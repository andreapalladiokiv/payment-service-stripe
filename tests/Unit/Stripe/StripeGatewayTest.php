<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
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
    $client = new readonly class($body, $status) implements \Stripe\HttpClient\ClientInterface
    {
        public function __construct(private array $body, private int $status) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            return [json_encode($this->body), $this->status, []];
        }
    };

    \Stripe\ApiRequestor::setHttpClient($client);
}

afterEach(function () {
    \Stripe\ApiRequestor::setHttpClient(\Stripe\HttpClient\CurlClient::instance());
});

it('keeps an existing non-empty customer link on purchase', function () {
    $pm = stripeSavedPaymentMethod();

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn('cus_existing');
    $customers->shouldNotReceive('saveAndAttach');

    $gateway = makeStripeGateway();
    $gateway->setCustomerRepository($customers);

    $request = $gateway->purchase([
        'gateway' => stripeGatewayCredential(),
        'instrument' => $pm,
    ]);

    expect($request->getCustomerReference())->toBe('cus_existing');
});

it('adopts the owning customer from Stripe when the local link is missing', function () {
    $pm = stripeSavedPaymentMethod();

    fakeStripeHttp(['id' => 'pm_123', 'object' => 'payment_method', 'customer' => 'cus_owner']);

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn(null);
    $customers->shouldReceive('saveAndAttach')
        ->once()
        ->withArgs(fn ($gatewayId, $instrument, $reference) => $reference === 'cus_owner');

    $resolver = Mockery::mock(GatewayInstrumentRepository::class);
    $resolver->shouldReceive('find')->andReturn('pm_123');

    $gateway = makeStripeGateway();
    $gateway->setCustomerRepository($customers);

    $request = $gateway->purchase([
        'gateway' => stripeGatewayCredential(),
        'instrument' => $pm,
        'referenceResolver' => $resolver,
    ]);

    expect($request->getCustomerReference())->toBe('cus_owner');
});

it('treats an empty-string customer link as missing and repairs it from Stripe', function () {
    $pm = stripeSavedPaymentMethod();

    fakeStripeHttp(['id' => 'pm_123', 'object' => 'payment_method', 'customer' => 'cus_owner']);

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn('');
    $customers->shouldReceive('saveAndAttach')->once();

    $resolver = Mockery::mock(GatewayInstrumentRepository::class);
    $resolver->shouldReceive('find')->andReturn('pm_123');

    $gateway = makeStripeGateway();
    $gateway->setCustomerRepository($customers);

    $request = $gateway->purchase([
        'gateway' => stripeGatewayCredential(),
        'instrument' => $pm,
        'referenceResolver' => $resolver,
    ]);

    expect($request->getCustomerReference())->toBe('cus_owner');
});

it('leaves the customer unset when Stripe reports the payment method has no owner', function () {
    $pm = stripeSavedPaymentMethod();

    fakeStripeHttp(['id' => 'pm_123', 'object' => 'payment_method', 'customer' => null]);

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn(null);
    $customers->shouldNotReceive('saveAndAttach');

    $resolver = Mockery::mock(GatewayInstrumentRepository::class);
    $resolver->shouldReceive('find')->andReturn('pm_123');

    $gateway = makeStripeGateway();
    $gateway->setCustomerRepository($customers);

    // No billing email on the parameters either, so no customer is created.
    $request = $gateway->purchase([
        'gateway' => stripeGatewayCredential(),
        'instrument' => $pm,
        'referenceResolver' => $resolver,
    ]);

    expect($request->getCustomerReference())->toBe('');
});

it('falls through gracefully when the Stripe lookup fails', function () {
    $pm = stripeSavedPaymentMethod();

    fakeStripeHttp(['error' => ['type' => 'invalid_request_error', 'message' => 'No such payment method']], 404);

    $customers = Mockery::mock(CustomerRepository::class);
    $customers->shouldReceive('findByInstrument')->andReturn(null);
    $customers->shouldNotReceive('saveAndAttach');

    $resolver = Mockery::mock(GatewayInstrumentRepository::class);
    $resolver->shouldReceive('find')->andReturn('pm_gone');

    $gateway = makeStripeGateway();
    $gateway->setCustomerRepository($customers);

    $request = $gateway->purchase([
        'gateway' => stripeGatewayCredential(),
        'instrument' => $pm,
        'referenceResolver' => $resolver,
    ]);

    expect($request->getCustomerReference())->toBe('');
});
