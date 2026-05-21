<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function threeDSStripeGateway(): StripeGateway
{
    $gw = new StripeGateway;
    $gw->initialize(['apiKey' => 'sk_test']);

    return $gw;
}

function threeDSCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'Stripe'; }
        public function getCredentials(): array { return []; }
    };
}

function threeDSCard(): CreditCard
{
    $enc = new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };

    return new CreditCard(
        Number::fromNumber('4242424242424242', $enc),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        Cvc::fromCvc('123', $enc),
    );
}

function threeDSDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };
}

function makeStripeThreeDS(): ThreeDSResult
{
    return new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-abc',
        ECICode::VisaSuccessful,
        'ds-txn-123',
        'acs-txn-456',
        ThreeDSVersion::V220,
    );
}

// ──────────────────────────────────────────────
//  Purchase — threeDS parameter
// ──────────────────────────────────────────────

it('stores threeDS parameter for purchase request', function () {
    $threeDS = makeStripeThreeDS();

    $request = threeDSStripeGateway()->purchase([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => threeDSCard(),
        'gateway' => threeDSCredential(),
        'decrypter' => threeDSDecrypter(),
        'threeDS' => $threeDS,
    ]);

    $storedThreeDS = $request->getThreeDS();

    expect($storedThreeDS)->toBeInstanceOf(ThreeDSResult::class)
        ->and($storedThreeDS->status)->toBe(ThreeDSStatus::Successful)
        ->and($storedThreeDS->authenticationValue)->toBe('cavv-abc')
        ->and($storedThreeDS->eci)->toBe(ECICode::VisaSuccessful)
        ->and($storedThreeDS->dsTransactionId)->toBe('ds-txn-123')
        ->and($storedThreeDS->acsTransactionId)->toBe('acs-txn-456')
        ->and($storedThreeDS->version)->toBe(ThreeDSVersion::V220);

    // getData() still works correctly
    $data = $request->getData();
    expect($data['amount'])->toBe(5000)
        ->and($data['currency'])->toBe('usd')
        ->and($data['payment_method_data']['type'])->toBe('card');
});

it('has null threeDS parameter for purchase when not provided', function () {
    $request = threeDSStripeGateway()->purchase([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => threeDSCard(),
        'gateway' => threeDSCredential(),
        'decrypter' => threeDSDecrypter(),
    ]);

    expect($request->getThreeDS())->toBeNull();
});

// ──────────────────────────────────────────────
//  Authorize — threeDS parameter
// ──────────────────────────────────────────────

it('stores threeDS parameter for authorize request', function () {
    $threeDS = makeStripeThreeDS();

    $request = threeDSStripeGateway()->authorize([
        'money' => new Money(3000, new Currency('EUR')),
        'instrument' => threeDSCard(),
        'gateway' => threeDSCredential(),
        'decrypter' => threeDSDecrypter(),
        'threeDS' => $threeDS,
    ]);

    $storedThreeDS = $request->getThreeDS();

    expect($storedThreeDS)->toBeInstanceOf(ThreeDSResult::class)
        ->and($storedThreeDS->status)->toBe(ThreeDSStatus::Successful)
        ->and($storedThreeDS->authenticationValue)->toBe('cavv-abc')
        ->and($storedThreeDS->eci)->toBe(ECICode::VisaSuccessful)
        ->and($storedThreeDS->dsTransactionId)->toBe('ds-txn-123')
        ->and($storedThreeDS->version)->toBe(ThreeDSVersion::V220);

    // getData() still works correctly
    $data = $request->getData();
    expect($data['amount'])->toBe(3000)
        ->and($data['currency'])->toBe('eur');
});

it('has null threeDS parameter for authorize when not provided', function () {
    $request = threeDSStripeGateway()->authorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => threeDSCard(),
        'gateway' => threeDSCredential(),
        'decrypter' => threeDSDecrypter(),
    ]);

    expect($request->getThreeDS())->toBeNull();
});
