<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Exception\IncompleteAuthentication;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\Authorize;
use Techork\PaymentService\Stripe\Charge;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * Where an authentication goes once the caller hands one over, on both operations that forward
 * one.
 *
 * Four tests here used to assert that the operation could hand its attestation back — through
 * `getThreeDS()`, an accessor omnipay's parameter bag supplied — and reasoned that since
 * `sendData()` read the same accessor, a correct round-trip implied a correct request. That
 * inference is gone with the bag: the attestation is a readonly field of
 * {@see PlacementCommand} and reading it back would assert only that PHP kept a property.
 *
 * So they assert the thing the round-trip stood in for, one layer further out: what
 * `payment_method_options` Stripe is actually sent. Nothing reaches the network — Stripe's SDK
 * resolves its HTTP client through the static `ApiRequestor::setHttpClient()`, and the
 * `afterEach` restores the real one.
 */
function threeDSRecordingApi(): object
{
    $client = new class implements ClientInterface
    {
        /** @var list<array<string, mixed>> */
        public array $params = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->params[] = is_array($params) ? $params : [];

            return [json_encode([
                'id' => 'pi_1',
                'object' => 'payment_intent',
                'status' => 'requires_capture',
            ]), 200, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

function threeDSCard(): CreditCard
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

function threeDSDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface
    {
        public function decrypt(string $d): string
        {
            return $d;
        }
    };
}

function makeStripeThreeDS(?string $authenticationValue = 'cavv-abc'): ThreeDSResult
{
    return new ThreeDSResult(
        ThreeDSStatus::Successful,
        $authenticationValue,
        ECICode::VisaSuccessful,
        'ds-txn-123',
        'acs-txn-456',
        ThreeDSVersion::V220,
    );
}

/**
 * @param  array<string, mixed>  $options
 */
function threeDSPlacement(string $operation, array $options): Authorize|Charge
{
    $infrastructure = new GatewayInfrastructure(
        Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        threeDSDecrypter(),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
        ['apiKey' => 'sk_test_fake'],
    );

    $settings = new StripeSettings('sk_test_fake');

    $command = new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: $options['instrument'] ?? Mockery::mock(PaymentInstrument::class),
        amount: $options['money'] ?? new Money(1000, new Currency('USD')),
        threeDS: $options['threeDS'] ?? null,
        initiation: PaymentInitiation::CardholderInitiated,
    );

    // Both verbs, because the attestation has to survive either route to Stripe and the two
    // build their card options block separately.
    return $operation === 'authorize'
        ? new Authorize($infrastructure, $settings, $command)
        : new Charge($infrastructure, $settings, $command);
}

it('forwards the attestation onto the card options block', function (string $operation) {
    $api = threeDSRecordingApi();

    $placement = threeDSPlacement($operation, [
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => threeDSCard(),
        'threeDS' => makeStripeThreeDS(),
    ]);

    $operation === 'authorize' ? $placement->authorize() : $placement->charge();

    expect($api->params[0]['payment_method_options'])->toBe([
        'card' => [
            'three_d_secure' => [
                'cryptogram' => 'cavv-abc',
                'transaction_id' => 'ds-txn-123',
                'ares_trans_status' => 'Y',
                'version' => '2.2.0',
                'electronic_commerce_indicator' => '05',
            ],
        ],
    ]);
})->with(['authorize', 'charge']);

it('sends no card options block when the payment carries no authentication', function (string $operation) {
    $api = threeDSRecordingApi();

    $placement = threeDSPlacement($operation, [
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => threeDSCard(),
    ]);

    $operation === 'authorize' ? $placement->authorize() : $placement->charge();

    expect($api->params[0])->not->toHaveKey('payment_method_options');
})->with(['authorize', 'charge']);

/**
 * The refusal has to escape rather than become a payment. An attestation with no cryptogram
 * carries no liability shift, and sending the block without one would ship an authentication the
 * issuer never applied — see {@see \Techork\PaymentService\Stripe\Concern\FormatsThreeDS}.
 * Asserted here as well as on the trait because it is the operation's `catch (ApiErrorException)`
 * that would swallow it if it were ever widened.
 */
it('refuses to place a payment carrying an attestation with no cryptogram', function (string $operation) {
    threeDSRecordingApi();

    $placement = threeDSPlacement($operation, [
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => threeDSCard(),
        'threeDS' => makeStripeThreeDS(null),
    ]);

    expect(fn () => $operation === 'authorize' ? $placement->authorize() : $placement->charge())
        ->toThrow(IncompleteAuthentication::class, 'missing cryptogram');
})->with(['authorize', 'charge']);
