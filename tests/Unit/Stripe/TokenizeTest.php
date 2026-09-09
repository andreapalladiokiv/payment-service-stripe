<?php

declare(strict_types=1);

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
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\StripeSettings;
use Techork\PaymentService\Stripe\Tokenize;

/**
 * Built directly, with the customer the gateway would have resolved passed in: a caller that says
 * nothing about one gets none, and the operation must not invent it.
 *
 * @param  array<string, mixed>  $options
 */
function stripeTokenize(array $options): Tokenize
{
    $infrastructure = new GatewayInfrastructure(
        $options['gateway'] ?? Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        $options['decrypter'] ?? Mockery::mock(DecryptInterface::class),
        $options['referenceResolver'] ?? Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        $options['customerRepository'] ?? Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
    );

    return new Tokenize($infrastructure, $options['settings'] ?? new StripeSettings, new VaultCommand(
        gatewayId: GatewayId::generate(),
        instrument: $options['instrument'] ?? Mockery::mock(PaymentInstrument::class),
        clientUniqueId: $options['clientUniqueId'] ?? null,
        customer: stripeSuiteCustomerFrom($options),
    ), $options['customerReference'] ?? null);
}

function tokenizeGateway(): StripeGateway
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

function tokenizePassthroughDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface
    {
        public function decrypt(string $data): string
        {
            return $data;
        }
    };
}

it('builds tokenize data for credit card', function () {
    $encrypter = new class implements EncryptInterface
    {
        public function encrypt(string $data): string
        {
            return $data;
        }
    };

    $card = new CreditCard(
        Number::fromNumber('4242424242424242', $encrypter),
        Expiration::fromMonthAndYear(6, 2028),
        new Holder('Jane Doe'),
        Cvc::fromCvc('456', $encrypter),
    );

    $data = stripeTokenize([
        'instrument' => $card,
        'decrypter' => tokenizePassthroughDecrypter(),
    ])->payload();

    expect($data['card']['number'])->toBe('4242424242424242')
        ->and($data['card']['exp_month'])->toBe('06')
        ->and($data['card']['exp_year'])->toBe('2028')
        ->and($data['card']['cvc'])->toBe('456')
        ->and($data['card']['name'])->toBe('Jane Doe');
});

it('throws on token instrument', function () {
    $token = new Token(
        TokenId::generate(),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    stripeTokenize([
        'instrument' => $token,
        'decrypter' => tokenizePassthroughDecrypter(),
    ])->payload();
})->throws(RuntimeException::class, 'Token does not support tokenization');

it('throws on cash instrument', function () {
    stripeTokenize([
        'instrument' => new Cash,
        'decrypter' => tokenizePassthroughDecrypter(),
    ])->payload();
})->throws(RuntimeException::class, 'Stripe does not support cash');
