<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\Authorize;
use Techork\PaymentService\Stripe\Charge;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * What `off_session` is set from, on both operations that send it.
 *
 * It used to be set from whether the instrument was a stored reference rather than a raw card:
 * anything paid on a saved payment method went out `off_session => true`. That is a different
 * question from the one the flag answers. `off_session` declares that the cardholder is not there
 * to be asked anything, and a customer paying with their saved card in a live checkout very much
 * is — so an ordinary cardholder-initiated payment was being declared unattended.
 *
 * It is the mirror of the failure a subscription renewal makes when it goes out unmarked, and it
 * costs the same: the network is told to apply the rules of a transaction that is not happening.
 * Off-session is what carries an authentication exemption and shifts who owns a dispute; claiming
 * it for an attended payment claims both wrongly.
 *
 * `initiation` is the fact, and it is a field of {@see PlacementCommand}, so it reaches every
 * operation — it is what the rebilling path sets and what the domain derives from the
 * stored-credential position.
 *
 * Nothing here reaches the network: Stripe's SDK resolves its HTTP client through the static
 * `ApiRequestor::setHttpClient()`, so a stub answers every call the client built inside the
 * operation makes, and `afterEach` restores the real one.
 */
function stripeOffSessionApi(): object
{
    $client = new class implements ClientInterface
    {
        /** @var list<array<string, mixed>> */
        public array $params = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->params[] = $params;

            return [json_encode([
                'id' => 'pi_1',
                'object' => 'payment_intent',
                'status' => 'requires_capture',
                'amount' => 1000,
                'currency' => 'usd',
            ]), 200, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

afterEach(fn () => ApiRequestor::setHttpClient(new CurlClient));

/**
 * A stored instrument, because the defect only shows on one: it is `payment_method` rather than
 * `payment_method_data` in the body that used to be read as "nobody is present".
 */
function stripeOffSessionInstrument(): PaymentMethod
{
    return new PaymentMethod(
        PaymentMethodId::generate(),
        new CreditCard(
            new Number('424242', '4242', CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );
}

/**
 * Built directly rather than through the gateway, because the gateway would go and resolve a
 * customer for the stored instrument and that is a different subject. What is in question here is
 * only the branch that decides the flag.
 *
 * @param  class-string<Authorize|Charge>  $class
 */
function stripeOffSessionSend(string $class, ?PaymentInitiation $initiation): object
{
    $api = stripeOffSessionApi();

    $instruments = Mockery::mock(GatewayInstrumentRepository::class);
    $instruments->shouldReceive('find')->andReturn('pm_saved');

    $infrastructure = new GatewayInfrastructure(
        Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        Mockery::mock(DecryptInterface::class),
        $instruments,
        Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
        ['apiKey' => 'sk_test_fake'],
    );

    $command = new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: stripeOffSessionInstrument(),
        amount: new Money(1000, new Currency('USD')),
        initiation: $initiation ?? PaymentInitiation::CardholderInitiated,
    );

    $operation = new $class($infrastructure, new StripeSettings('sk_test_fake'), $command);

    $operation instanceof Authorize ? $operation->authorize() : $operation->charge();

    return $api;
}

it('does not declare an attended payment off-session, even on a saved card', function (string $class) {
    // The defect. A stored `payment_method` used to be read as "nobody is present" — so every
    // returning customer's checkout went out claiming an exemption it had no right to.
    $api = stripeOffSessionSend($class, PaymentInitiation::CardholderInitiated);

    expect($api->params[0])->not->toHaveKey('off_session')
        ->and($api->params[0]['payment_method'])->toBe('pm_saved');
})->with([Authorize::class, Charge::class]);

it('declares an unattended payment off-session', function (string $class, PaymentInitiation $initiation) {
    // The case the flag exists for, and the one a subscription renewal takes.
    $api = stripeOffSessionSend($class, $initiation);

    // The string, not the boolean: that is what the SDK puts on the wire, and pinning the wire
    // form is the point of reading the params rather than the operation object.
    expect($api->params[0]['off_session'])->toBe('true');
})->with([Authorize::class, Charge::class])
    ->with([
        'recurring' => PaymentInitiation::MerchantRecurring,
        'unscheduled' => PaymentInitiation::MerchantUnscheduled,
    ]);

it('treats an unstated initiation as attended', function (string $class) {
    // The default everywhere else in this package is CardholderInitiated, and it is the safe one
    // here: a payment that forgot to say goes out claiming nothing, rather than claiming an
    // exemption on a cardholder who is standing right there.
    $api = stripeOffSessionSend($class, null);

    expect($api->params[0])->not->toHaveKey('off_session');
})->with([Authorize::class, Charge::class]);
