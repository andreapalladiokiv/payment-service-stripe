<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\RegisterCustomerCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\Authorize;
use Techork\PaymentService\Stripe\Cancel;
use Techork\PaymentService\Stripe\Capture;
use Techork\PaymentService\Stripe\Charge;
use Techork\PaymentService\Stripe\CreateCustomer;
use Techork\PaymentService\Stripe\Refund;
use Techork\PaymentService\Stripe\RegisterPaymentMethod;
use Techork\PaymentService\Stripe\StripeSettings;
use Techork\PaymentService\Stripe\Tokenize;

/**
 * Live integration tests against the Stripe TEST MODE, driven through the same operation
 * classes production calls. Skipped unless a key is provided:
 *
 *   STRIPE_API_KEY=sk_test_... \
 *   vendor/bin/pest src/Stripe/tests/Integration/StripeTestModeTest.php
 *
 * Stripe has no sandbox flag — test mode is the key alone — so a key that does not
 * start with `sk_test_` refuses to run rather than charging a live account.
 *
 * Safety: test-mode money is fictitious, so capture (Settle's analogue) and
 * charge+refund run here as they do in the ConnexPay sandbox suite. Every
 * authorization that is not captured is cancelled in the test itself, and every
 * request carries its own idempotency key (STRLIVE-<n>-<ts>) so a re-run cannot
 * double-create anything.
 */
const STRIPE_LIVE_SKIP = 'Set STRIPE_API_KEY (sk_test_...) to run the Stripe test-mode integration tests.';

function stripeLiveConfigured(): bool
{
    return str_starts_with((string) getenv('STRIPE_API_KEY'), 'sk_test_');
}

function stripeLiveSettings(): StripeSettings
{
    return new StripeSettings((string) getenv('STRIPE_API_KEY'));
}

function stripeLiveInfrastructure(): GatewayInfrastructure
{
    return new GatewayInfrastructure(
        new readonly class implements GatewayCredential
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
        },
        new readonly class implements DecryptInterface
        {
            public function decrypt(string $data): string
            {
                return $data;
            }
        },
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
        ['apiKey' => (string) getenv('STRIPE_API_KEY')],
    );
}

function stripeLiveCard(): CreditCard
{
    return new CreditCard(
        Number::fromNumber('4242424242424242', new readonly class implements EncryptInterface
        {
            public function encrypt(string $data): string
            {
                return $data;
            }
        }),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Foundation Test'),
        Cvc::fromCvc('123', new readonly class implements EncryptInterface
        {
            public function encrypt(string $data): string
            {
                return $data;
            }
        }),
    );
}

it('authorizes a manual-capture payment and answers requires_capture', function () {
    $result = new Authorize(
        stripeLiveInfrastructure(),
        stripeLiveSettings(),
        new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: stripeLiveCard(),
            amount: new Money(1503, new Currency('USD')),
            clientUniqueId: 'STRLIVE-1-'.time(),
            customer: stripeSuiteCustomer(),
        ),
    )->authorize();

    expect($result->success)->toBeTrue($result->message ?? 'authorize failed')
        ->and($result->reference)->toStartWith('pi_');

    // Released in this test, as everywhere in this suite: a hold the run leaves behind
    // is the one thing a test-mode account cannot afford.
    $cancel = new Cancel(
        stripeLiveSettings(),
        new CancelCommand(GatewayId::generate(), (string) $result->reference),
    )->cancel();

    expect($cancel->success)->toBeTrue($cancel->message ?? 'cancel failed');
})->skip(! stripeLiveConfigured(), STRIPE_LIVE_SKIP);

it('cancels a held auth and answers success only because Stripe really canceled', function () {
    $auth = new Authorize(
        stripeLiveInfrastructure(),
        stripeLiveSettings(),
        new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: stripeLiveCard(),
            amount: new Money(511, new Currency('USD')),
            clientUniqueId: 'STRLIVE-2-'.time(),
        ),
    )->authorize();

    expect($auth->success)->toBeTrue($auth->message ?? 'authorize failed');

    // Cancel is only a success when the intent came back `canceled` — a 200 whose
    // status stayed put reads as a failure (src/Stripe/src/Cancel.php). That is the
    // guarantee this asserts, not merely that HTTP went through.
    $result = new Cancel(
        stripeLiveSettings(),
        new CancelCommand(GatewayId::generate(), (string) $auth->reference),
    )->cancel();

    expect($result->success)->toBeTrue($result->message ?? 'cancel failed');
})->skip(! stripeLiveConfigured(), STRIPE_LIVE_SKIP);

it('captures a held auth in full', function () {
    $auth = new Authorize(
        stripeLiveInfrastructure(),
        stripeLiveSettings(),
        new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: stripeLiveCard(),
            amount: new Money(513, new Currency('USD')),
            clientUniqueId: 'STRLIVE-3-'.time(),
        ),
    )->authorize();

    expect($auth->success)->toBeTrue($auth->message ?? 'authorize failed');

    $result = new Capture(
        stripeLiveSettings(),
        new CaptureCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: (string) $auth->reference,
            amount: new Money(513, new Currency('USD')),
        ),
    )->capture();

    expect($result->success)->toBeTrue($result->message ?? 'capture failed')
        ->and($result->reference)->toBe($auth->reference);
})->skip(! stripeLiveConfigured(), STRIPE_LIVE_SKIP);

it('charges a sale outright and refunds it', function () {
    $charge = new Charge(
        stripeLiveInfrastructure(),
        stripeLiveSettings(),
        new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: stripeLiveCard(),
            amount: new Money(509, new Currency('USD')),
            clientUniqueId: 'STRLIVE-4-'.time(),
        ),
    )->charge();

    expect($charge->success)->toBeTrue($charge->message ?? 'charge failed')
        ->and($charge->reference)->toStartWith('pi_');

    $result = new Refund(
        stripeLiveSettings(),
        new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: (string) $charge->reference,
            amount: new Money(509, new Currency('USD')),
            clientUniqueId: 'STRLIVE-4-refund-'.time(),
        ),
    )->refund();

    expect($result->success)->toBeTrue($result->message ?? 'refund failed')
        ->and($result->reference)->toStartWith('re_');
})->skip(! stripeLiveConfigured(), STRIPE_LIVE_SKIP);

it('tokenizes a raw card into a single-use token', function () {
    $result = new Tokenize(
        stripeLiveInfrastructure(),
        stripeLiveSettings(),
        new VaultCommand(
            gatewayId: GatewayId::generate(),
            instrument: stripeLiveCard(),
            clientUniqueId: 'STRLIVE-5-'.time(),
        ),
    )->tokenize();

    expect($result->success)->toBeTrue($result->message ?? 'tokenize failed')
        ->and($result->reference)->toStartWith('tok_');
})->skip(! stripeLiveConfigured(), STRIPE_LIVE_SKIP);

it('creates a customer it can later attach an instrument to', function () {
    $result = new CreateCustomer(
        stripeLiveSettings(),
        stripeSuiteCustomer(firstName: 'Foundation', lastName: 'Test'),
    )->create();

    expect($result->success)->toBeTrue($result->message ?? 'create customer failed')
        ->and($result->reference)->toStartWith('cus_');
})->skip(! stripeLiveConfigured(), STRIPE_LIVE_SKIP);

it('registers a payment method against a real customer', function () {
    // The cus_ the stored-instrument chain keys on: registerPaymentMethod attaches the
    // card to it, and a null reference would be refused as a wiring error.
    $customer = new CreateCustomer(
        stripeLiveSettings(),
        stripeSuiteCustomer(firstName: 'Foundation', lastName: 'Test'),
    )->create();

    expect($customer->success)->toBeTrue($customer->message ?? 'create customer failed');

    $result = new RegisterPaymentMethod(
        stripeLiveInfrastructure(),
        stripeLiveSettings(),
        new VaultCommand(
            gatewayId: GatewayId::generate(),
            instrument: stripeLiveCard(),
            clientUniqueId: 'STRLIVE-7-'.time(),
        ),
        (string) $customer->reference,
    )->register();

    expect($result->success)->toBeTrue($result->message ?? 'register failed')
        ->and($result->reference)->toStartWith('pm_');
})->skip(! stripeLiveConfigured(), STRIPE_LIVE_SKIP);