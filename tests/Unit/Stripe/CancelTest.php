<?php

declare(strict_types=1);

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\Cancel;
use Techork\PaymentService\Stripe\StripeGateway;
use Techork\PaymentService\Stripe\StripeSettings;

function cancelGateway(): StripeGateway
{
    $gw = new StripeGateway;
    $gw->configure(new GatewayInfrastructure(
        Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
        ['apiKey' => 'sk_test_fake'],
    ));

    return $gw;
}

function cancelFor(string $reference = 'pi_abc123'): Cancel
{
    return new Cancel(new StripeSettings('sk_test_fake'), new CancelCommand(GatewayId::generate(), $reference));
}

function stripeCancelFakeApi(array $body, int $status = 200): object
{
    $client = new class($body, $status) implements ClientInterface
    {
        /** @var list<array{method: string, url: string, headers: array, params: array}> */
        public array $calls = [];

        public function __construct(private array $body, private int $status) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->calls[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

            return [json_encode($this->body), $this->status, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

it('builds cancel data with transactionReference', function () {
    expect(cancelFor('pi_abc123')->payload())->toBe(['payment_intent' => 'pi_abc123']);
});

/*
 * `it('throws when transactionReference is missing')` lived here. It cannot: a
 * {@see CancelCommand} has no state without one, so the case the test described is now a
 * constructor signature rather than a `validate()` call — which is the whole point of the
 * command replacing the parameter array.
 */

/*
 * Three tests here built a `VoidResponse` by hand and asked it what it thought of a status,
 * which said nothing about whether the operation ever produced that shape. The response class
 * is gone and the same judgement now lives in {@see Cancel::cancel()}, so the three below ask
 * the operation the same questions through the answer Stripe actually gives it.
 */

/**
 * Stripe answers 200 for a cancel it did not perform, so the status is what decides — not the
 * absence of an exception. Reading success off the presence of an id would report every one of
 * those as a cancelled payment.
 */
it('reports a cancel that Stripe actually performed', function () {
    stripeCancelFakeApi(['id' => 'pi_abc123', 'object' => 'payment_intent', 'status' => 'canceled']);

    $result = cancelFor('pi_abc123')->cancel();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('pi_abc123')
        ->and($result->message)->toBeNull();
});

/**
 * Stripe answers 200 for a cancel it did not perform, so the status is the answer and the absence
 * of an exception is not.
 *
 * The wording is the one the shared folder produced for a response that reported itself
 * unsuccessful and carried no message — which is what this path always was. Naming the status
 * would read better; it is left alone because it is what a merchant is told.
 */
it('refuses a 200 that left the intent in some other status', function () {
    stripeCancelFakeApi(['id' => 'pi_abc123', 'object' => 'payment_intent', 'status' => 'requires_payment_method']);

    $result = cancelFor('pi_abc123')->cancel();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Gateway returned an unsuccessful response.');
});

it('converts a Stripe API error into a failed result carrying the reason', function () {
    stripeCancelFakeApi(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'No such payment intent']],
        404,
    );

    $result = cancelFor('pi_abc123')->cancel();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toBe('No such payment intent');
});
