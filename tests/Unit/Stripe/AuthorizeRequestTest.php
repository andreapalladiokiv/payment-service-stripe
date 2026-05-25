<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
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
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function stripeGateway(): StripeGateway
{
    $gw = new StripeGateway;
    $gw->initialize(['apiKey' => 'sk_test_fake']);

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
    $mock = Mockery::mock(GatewayInstrumentRepository::class);
    $mock->shouldReceive('find')->andReturn($reference);

    return $mock;
}

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

it('builds authorize data for credit card', function () {
    $request = stripeGateway()->authorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => testCard(),
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
    ]);

    $data = $request->getData();

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

    $request = stripeGateway()->authorize([
        'money' => new Money(3000, new Currency('EUR')),
        'instrument' => $token,
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'referenceResolver' => fakeReferenceResolver('tok_abc123'),
    ]);

    $data = $request->getData();

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

    $request = stripeGateway()->authorize([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => $pm,
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'referenceResolver' => fakeReferenceResolver('pm_xyz789'),
    ]);

    $data = $request->getData();

    expect($data['payment_method'])->toBe('pm_xyz789')
        ->and($data)->not->toHaveKey('payment_method_data');
});

it('builds authorize data for payment method with tok_ reference', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        testCard(),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    $request = stripeGateway()->authorize([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => $pm,
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'referenceResolver' => fakeReferenceResolver('tok_legacy123'),
    ]);

    $data = $request->getData();

    expect($data['payment_method'])->toBe('tok_legacy123')
        ->and($data)->not->toHaveKey('payment_method_data');
});

it('includes customer reference when provided', function () {
    $request = stripeGateway()->authorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => testCard(),
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'customerReference' => 'cus_abc',
    ]);

    $data = $request->getData();

    expect($data['customer'])->toBe('cus_abc');
});

it('includes statement_descriptor when statementDescription is set', function () {
    $request = stripeGateway()->authorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => testCard(),
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'statementDescription' => 'ACME Trip 42',
    ]);

    expect($request->getData()['statement_descriptor'])->toBe('ACME Trip 42');
});

it('omits statement_descriptor when statementDescription is null or empty', function () {
    $request = stripeGateway()->authorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => testCard(),
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'statementDescription' => '',
    ]);

    expect($request->getData())->not->toHaveKey('statement_descriptor');
});

it('throws on cash instrument', function () {
    $request = stripeGateway()->authorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => new \Techork\PaymentService\Common\ValueObject\Cash,
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
    ]);

    $request->getData();
})->throws(RuntimeException::class, 'Stripe does not support cash');

it('stores threeDS parameter when provided', function () {
    $threeDS = new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-xyz',
        ECICode::MastercardSuccessful,
        'ds-txn-789',
        'acs-txn-012',
        ThreeDSVersion::V220,
    );

    $request = stripeGateway()->authorize([
        'money' => new Money(3000, new Currency('EUR')),
        'instrument' => testCard(),
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
        'threeDS' => $threeDS,
    ]);

    // threeDS is used by sendData(), verify the parameter is stored correctly
    $storedThreeDS = $request->getThreeDS();

    expect($storedThreeDS)->toBeInstanceOf(ThreeDSResult::class)
        ->and($storedThreeDS->status)->toBe(ThreeDSStatus::Successful)
        ->and($storedThreeDS->authenticationValue)->toBe('cavv-xyz')
        ->and($storedThreeDS->eci)->toBe(ECICode::MastercardSuccessful)
        ->and($storedThreeDS->dsTransactionId)->toBe('ds-txn-789')
        ->and($storedThreeDS->version)->toBe(ThreeDSVersion::V220);

    // getData() still works correctly
    $data = $request->getData();
    expect($data['amount'])->toBe(3000)
        ->and($data['currency'])->toBe('eur');
});

it('has null threeDS parameter when not provided', function () {
    $request = stripeGateway()->authorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => testCard(),
        'gateway' => fakeCredential(),
        'decrypter' => fakeDecrypter(),
    ]);

    expect($request->getThreeDS())->toBeNull();
});
