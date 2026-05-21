<?php

declare(strict_types=1);

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
use Techork\PaymentService\Stripe\StripeGateway;

it('builds tokenize data for credit card', function () {
    $gw = new StripeGateway;
    $gw->initialize(['apiKey' => 'sk_test']);

    $encrypter = new class implements \Techork\PaymentService\Common\Contract\EncryptInterface {
        public function encrypt(string $data): string { return $data; }
    };

    $card = new CreditCard(
        Number::fromNumber('4242424242424242', $encrypter),
        Expiration::fromMonthAndYear(6, 2028),
        new Holder('Jane Doe'),
        Cvc::fromCvc('456', $encrypter),
    );

    $decrypter = new class implements \Techork\PaymentService\Common\Contract\DecryptInterface {
        public function decrypt(string $data): string { return $data; }
    };

    $request = $gw->createCard([
        'instrument' => $card,
        'decrypter' => $decrypter,
    ]);

    $data = $request->getData();

    expect($data['card']['number'])->toBe('4242424242424242')
        ->and($data['card']['exp_month'])->toBe('06')
        ->and($data['card']['exp_year'])->toBe('2028')
        ->and($data['card']['cvc'])->toBe('456')
        ->and($data['card']['name'])->toBe('Jane Doe');
});

it('throws on token instrument', function () {
    $gw = new StripeGateway;
    $gw->initialize(['apiKey' => 'sk_test']);

    $card = new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        new Cvc,
    );

    $token = new Token(
        TokenId::generate(),
        $card,
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $decrypter = new class implements \Techork\PaymentService\Common\Contract\DecryptInterface {
        public function decrypt(string $data): string { return $data; }
    };

    $request = $gw->createCard([
        'instrument' => $token,
        'decrypter' => $decrypter,
    ]);

    $request->getData();
})->throws(RuntimeException::class, 'Token does not support tokenization');

it('throws on cash instrument', function () {
    $gw = new StripeGateway;
    $gw->initialize(['apiKey' => 'sk_test']);

    $decrypter = new class implements \Techork\PaymentService\Common\Contract\DecryptInterface {
        public function decrypt(string $data): string { return $data; }
    };

    $request = $gw->createCard([
        'instrument' => new Cash,
        'decrypter' => $decrypter,
    ]);

    $request->getData();
})->throws(RuntimeException::class, 'Stripe does not support cash');
