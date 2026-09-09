<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\RegisterPaymentMethod;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * The operation is built directly rather than fetched off the gateway: the `registering()`
 * accessor that handed one back unperformed is gone, and what it supplied — the infrastructure
 * and the customer resolved for the instrument — are arguments here. A case that says nothing
 * about a customer gets none, which is the shape the refusal below is about.
 *
 * @param  array<string, mixed>  $options
 */
function stripeRegister(array $options): RegisterPaymentMethod
{
    $infrastructure = new GatewayInfrastructure(
        $options['gateway'] ?? Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        $options['decrypter'] ?? Mockery::mock(DecryptInterface::class),
        $options['referenceResolver'] ?? Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        $options['customerRepository'] ?? Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
    );

    return new RegisterPaymentMethod($infrastructure, $options['settings'] ?? new StripeSettings, new VaultCommand(
        gatewayId: GatewayId::generate(),
        instrument: $options['instrument'] ?? Mockery::mock(PaymentInstrument::class),
        clientUniqueId: $options['clientUniqueId'] ?? null,
        customer: stripeSuiteCustomerFrom($options),
    ), $options['customerReference'] ?? null);
}

function registerGateway(): StripeGateway
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

function registerCredential(): GatewayCredential
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

function registerPassthroughDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface
    {
        public function decrypt(string $d): string
        {
            return $d;
        }
    };
}

function registerTestCard(): CreditCard
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
        Expiration::fromMonthAndYear(3, 2029),
        new Holder('John'),
        Cvc::fromCvc('321', $enc),
    );
}

it('builds payment method data for credit card', function () {
    $data = stripeRegister([
        'instrument' => registerTestCard(),
        'gateway' => registerCredential(),
        'decrypter' => registerPassthroughDecrypter(),
    ])->payload();

    expect($data['payment_method_data']['type'])->toBe('card')
        ->and($data['payment_method_data']['card']['number'])->toBe('4242424242424242')
        ->and($data['payment_method_data']['card']['exp_month'])->toBe(3)
        ->and($data['payment_method_data']['card']['exp_year'])->toBe(2029);
});

it('builds payment method data for token via reference', function () {
    $token = new Token(
        TokenId::generate(),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('T'),
            new Cvc,
        ),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $refResolver = Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]);
    $refResolver->shouldReceive('find')->andReturn('tok_stripe_ref');

    $data = stripeRegister([
        'instrument' => $token,
        'gateway' => registerCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => $refResolver,
    ])->payload();

    expect($data['payment_method_data']['type'])->toBe('card')
        ->and($data['payment_method_data']['card']['token'])->toBe('tok_stripe_ref');
});

it('throws on payment method instrument', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('T'),
            new Cvc,
        ),
    );

    stripeRegister([
        'instrument' => $pm,
        'gateway' => registerCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
    ])->payload();
})->throws(RuntimeException::class, 'Cannot create a Stripe PaymentMethod from an existing PaymentMethod');

/**
 * Registration promises an instrument chargeable again later. Stripe grants that only
 * to a PaymentMethod attached to a Customer — an unattached one is spent by the
 * SetupIntent confirm and rejected on every use after. Reporting success here would
 * store a `pm_xxx` that fails at payment time, where it reads as a decline rather than
 * as the missing customer it is.
 */
it('refuses to register a payment method that would have no customer', function () {
    // No customer repository resolves anything, so nothing supplies a customerReference.
    $result = stripeRegister([
        'instrument' => registerTestCard(),
        'gateway' => registerCredential(),
        'decrypter' => registerPassthroughDecrypter(),
    ])->register();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toContain('needs a customer');
});
