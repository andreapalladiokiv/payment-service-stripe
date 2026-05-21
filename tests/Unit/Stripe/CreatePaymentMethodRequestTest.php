<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
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
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

it('builds payment method data for credit card', function () {
    $enc = new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };
    $dec = new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };

    $card = new CreditCard(
        Number::fromNumber('4242424242424242', $enc),
        Expiration::fromMonthAndYear(3, 2029),
        new Holder('John'),
        Cvc::fromCvc('321', $enc),
    );

    $credential = new readonly class implements GatewayCredential {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'Stripe'; }
        public function getCredentials(): array { return []; }
    };

    $gw = new StripeGateway;
    $gw->initialize(['apiKey' => 'sk_test']);

    $request = $gw->createPaymentMethod([
        'instrument' => $card,
        'gateway' => $credential,
        'decrypter' => $dec,
    ]);

    $data = $request->getData();

    expect($data['payment_method_data']['type'])->toBe('card')
        ->and($data['payment_method_data']['card']['number'])->toBe('4242424242424242')
        ->and($data['payment_method_data']['card']['exp_month'])->toBe(3)
        ->and($data['payment_method_data']['card']['exp_year'])->toBe(2029);
});

it('builds payment method data for token via reference', function () {
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('424242', '4242', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $credential = new readonly class implements GatewayCredential {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'Stripe'; }
        public function getCredentials(): array { return []; }
    };

    $refResolver = Mockery::mock(GatewayInstrumentRepository::class);
    $refResolver->shouldReceive('find')->andReturn('tok_stripe_ref');

    $gw = new StripeGateway;
    $gw->initialize(['apiKey' => 'sk_test']);

    $request = $gw->createPaymentMethod([
        'instrument' => $token,
        'gateway' => $credential,
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => $refResolver,
    ]);

    $data = $request->getData();

    expect($data['payment_method_data']['type'])->toBe('card')
        ->and($data['payment_method_data']['card']['token'])->toBe('tok_stripe_ref');
});

it('throws on payment method instrument', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        new CreditCard(new Number('424242', '4242', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    $credential = new readonly class implements GatewayCredential {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'Stripe'; }
        public function getCredentials(): array { return []; }
    };

    $gw = new StripeGateway;
    $gw->initialize(['apiKey' => 'sk_test']);

    $request = $gw->createPaymentMethod([
        'instrument' => $pm,
        'gateway' => $credential,
        'decrypter' => Mockery::mock(DecryptInterface::class),
    ]);

    $request->getData();
})->throws(RuntimeException::class, 'Cannot create a Stripe PaymentMethod from an existing PaymentMethod');
