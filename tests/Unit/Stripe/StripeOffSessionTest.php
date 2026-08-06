<?php

declare(strict_types=1);

use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Stripe\AuthorizeRequest;
use Techork\PaymentService\Stripe\PurchaseRequest;

/**
 * What `off_session` is set from, on both request classes that send it.
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
 * `initiation` is the fact and it already reaches every request — it is what the router sets on
 * the rebilling path and what the domain derives from the stored-credential position.
 *
 * Nothing here reaches the network: Stripe's SDK resolves its HTTP client through the static
 * `ApiRequestor::setHttpClient()`, so a stub answers every call the client built inside
 * `sendData()` makes, and `afterEach` restores the real one.
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
 * @param  class-string<AuthorizeRequest|PurchaseRequest>  $class
 */
function stripeOffSessionSend(string $class, ?PaymentInitiation $initiation): object
{
    $api = stripeOffSessionApi();

    $request = new $class(new OmnipayClient, new HttpRequest);
    $request->initialize(array_filter([
        'apiKey' => 'sk_test_fake',
        'money' => Money::USD(1000),
        'initiation' => $initiation,
    ], static fn (mixed $v): bool => $v !== null));

    // Straight to sendData: what getData() does with an instrument is a different subject, and
    // this needs only the branch that decides the flag.
    $request->sendData(['amount' => 1000, 'currency' => 'usd', 'payment_method' => 'pm_saved']);

    return $api;
}

it('does not declare an attended payment off-session, even on a saved card', function (string $class) {
    // The defect. `payment_method` rather than `payment_method_data` means the card is stored,
    // which used to be read as "nobody is present" — so every returning customer's checkout went
    // out claiming an exemption it had no right to.
    $api = stripeOffSessionSend($class, PaymentInitiation::CardholderInitiated);

    expect($api->params[0])->not->toHaveKey('off_session')
        ->and($api->params[0]['payment_method'])->toBe('pm_saved');
})->with([AuthorizeRequest::class, PurchaseRequest::class]);

it('declares an unattended payment off-session', function (string $class, PaymentInitiation $initiation) {
    // The case the flag exists for, and the one a subscription renewal takes.
    $api = stripeOffSessionSend($class, $initiation);

    // The string, not the boolean: that is what the SDK puts on the wire, and pinning the wire
    // form is the point of reading the params rather than the request object.
    expect($api->params[0]['off_session'])->toBe('true');
})->with([AuthorizeRequest::class, PurchaseRequest::class])
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
})->with([AuthorizeRequest::class, PurchaseRequest::class]);
