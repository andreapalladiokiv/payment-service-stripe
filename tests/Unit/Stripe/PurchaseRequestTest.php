<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function purchaseStripeGateway(): StripeGateway
{
    $gw = new StripeGateway;
    $gw->initialize(['apiKey' => 'sk_test']);

    return $gw;
}

function purchaseCredential(): GatewayCredential
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

it('builds purchase data for credit card', function () {
    $enc = new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };
    $dec = new class implements DecryptInterface
    {
        public function decrypt(string $d): string
        {
            return $d;
        }
    };

    $card = new CreditCard(
        Number::fromNumber('4242424242424242', $enc),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        Cvc::fromCvc('123', $enc),
    );

    $request = purchaseStripeGateway()->purchase([
        'money' => new Money(7500, new Currency('GBP')),
        'instrument' => $card,
        'gateway' => purchaseCredential(),
        'decrypter' => $dec,
    ]);

    $data = $request->getData();

    expect($data['amount'])->toBe(7500)
        ->and($data['currency'])->toBe('gbp')
        ->and($data['payment_method_data']['type'])->toBe('card')
        ->and($data['payment_method_data']['card']['number'])->toBe('4242424242424242');
});

it('builds purchase data for token', function () {
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('424242', '4242', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('tok_abc');

    $request = purchaseStripeGateway()->purchase([
        'money' => new Money(1000, new Currency('USD')),
        'instrument' => $token,
        'gateway' => purchaseCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => $ref,
    ]);

    $data = $request->getData();

    expect($data['payment_method_data']['card']['token'])->toBe('tok_abc');
});

it('stores threeDS parameter when provided', function () {
    $enc = new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };
    $dec = new class implements DecryptInterface
    {
        public function decrypt(string $d): string
        {
            return $d;
        }
    };

    $threeDS = new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-abc',
        ECICode::VisaSuccessful,
        'ds-txn-123',
        'acs-txn-456',
        ThreeDSVersion::V220,
    );

    $card = new CreditCard(
        Number::fromNumber('4242424242424242', $enc),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        Cvc::fromCvc('123', $enc),
    );

    $request = purchaseStripeGateway()->purchase([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => purchaseCredential(),
        'decrypter' => $dec,
        'threeDS' => $threeDS,
    ]);

    // threeDS is used by sendData(), verify the parameter is stored correctly
    $storedThreeDS = $request->getThreeDS();

    expect($storedThreeDS)->toBeInstanceOf(ThreeDSResult::class)
        ->and($storedThreeDS->status)->toBe(ThreeDSStatus::Successful)
        ->and($storedThreeDS->authenticationValue)->toBe('cavv-abc')
        ->and($storedThreeDS->eci)->toBe(ECICode::VisaSuccessful)
        ->and($storedThreeDS->dsTransactionId)->toBe('ds-txn-123')
        ->and($storedThreeDS->version)->toBe(ThreeDSVersion::V220);

    // getData() still works correctly
    $data = $request->getData();
    expect($data['amount'])->toBe(5000)
        ->and($data['currency'])->toBe('usd')
        ->and($data['payment_method_data']['type'])->toBe('card');
});

it('includes statement_descriptor when statementDescription is set', function () {
    $enc = new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };
    $dec = new class implements DecryptInterface
    {
        public function decrypt(string $d): string
        {
            return $d;
        }
    };

    $card = new CreditCard(
        Number::fromNumber('4242424242424242', $enc),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        Cvc::fromCvc('123', $enc),
    );

    $request = purchaseStripeGateway()->purchase([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => purchaseCredential(),
        'decrypter' => $dec,
        'statementDescription' => 'ACME Trip 42',
    ]);

    expect($request->getData()['statement_descriptor'])->toBe('ACME Trip 42');
});

it('builds hosted-checkout marker data for HostedPayment instrument', function () {
    $hosted = new HostedPayment(
        successUrl: 'https://merchant.example/success',
        cancelUrl: 'https://merchant.example/cancel',
    );

    $request = purchaseStripeGateway()->purchase([
        'money' => new Money(1500, new Currency('USD')),
        'instrument' => $hosted,
        'gateway' => purchaseCredential(),
    ]);

    $data = $request->getData();

    expect($data['_hosted'])->toBeTrue()
        ->and($data['success_url'])->toBe('https://merchant.example/success')
        ->and($data['cancel_url'])->toBe('https://merchant.example/cancel')
        ->and($data['amount'])->toBe(1500)
        ->and($data['currency'])->toBe('usd');
});

it('has null threeDS parameter when not provided', function () {
    $enc = new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };
    $dec = new class implements DecryptInterface
    {
        public function decrypt(string $d): string
        {
            return $d;
        }
    };

    $card = new CreditCard(
        Number::fromNumber('4242424242424242', $enc),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        Cvc::fromCvc('123', $enc),
    );

    $request = purchaseStripeGateway()->purchase([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => $card,
        'gateway' => purchaseCredential(),
        'decrypter' => $dec,
    ]);

    expect($request->getThreeDS())->toBeNull();
});
