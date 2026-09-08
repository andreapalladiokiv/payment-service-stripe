<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
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
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\Charge;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\StripeSettings;

function chargeStripeGateway(): StripeGateway
{
    $gw = new StripeGateway;
    $gw->configure(new GatewayInfrastructure(
        Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
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
        $options['customerRepository'] ?? Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
    );

    return new Charge($infrastructure, $options['settings'] ?? new StripeSettings, new PlacementCommand(
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
