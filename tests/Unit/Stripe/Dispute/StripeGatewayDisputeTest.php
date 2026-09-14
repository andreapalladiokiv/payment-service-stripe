<?php

declare(strict_types=1);

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Gateway\Command\DisputeCaseQuery;
use Techork\PaymentService\Gateway\Command\DisputeConcessionCommand;
use Techork\PaymentService\Gateway\Command\DisputeEvidenceCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Role\ConcedesDisputes;
use Techork\PaymentService\Gateway\Role\ReadsDisputeCases;
use Techork\PaymentService\Gateway\Role\SubmitsDisputeEvidence;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Stripe\StripeGateway;

/**
 * The driver declares the three dispute roles, and each method reaches the endpoint it names.
 *
 * The roles stand outside the `Gateway` composite on purpose — a provider that acquires has no
 * dispute surface from that fact alone — so nothing in the interface would fail if a driver
 * declared one and never implemented it. These four assertions are that check, and the three
 * endpoint assertions are what stops a delegation pointing at the wrong operation: evidence staged
 * through the close endpoint, or a close sent as an evidence update, is money.
 */
function stripeDisputeGatewayFakeApi(array $body, int $status = 200, array $others = []): object
{
    $client = new class($body, $status, $others) implements ClientInterface
    {
        /** @var list<array{url: string}> */
        public array $calls = [];

        /** @param array<string, array<string, mixed>> $others */
        public function __construct(private array $body, private int $status, private array $others) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->calls[] = ['url' => (string) $absUrl];

            foreach ($this->others as $fragment => $body) {
                if (str_contains((string) $absUrl, $fragment)) {
                    return [json_encode($body), 200, []];
                }
            }

            return [json_encode($this->body), $this->status, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

function stripeDisputeSuiteGateway(): StripeGateway
{
    $gateway = new StripeGateway;
    $gateway->configure(new GatewayInfrastructure(
        Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
        ['apiKey' => 'sk_test_fake'],
    ));

    return $gateway;
}

/** @return array<string, mixed> */
function stripeDisputeSuiteDispute(string $status = 'needs_response'): array
{
    return [
        'id' => 'dp_1QkDisputeCaseAlpha',
        'object' => 'dispute',
        'status' => $status,
        // The deadline travels with the case: a read of a case the provider is waiting on cannot
        // be built without one.
        'evidence_details' => ['due_by' => 1738195199],
        'payment_method_details' => ['card' => ['brand' => 'visa', 'case_type' => 'chargeback', 'network_reason_code' => '10.4'], 'type' => 'card'],
    ];
}

it('declares all three dispute roles', function () {
    $gateway = stripeDisputeSuiteGateway();

    expect($gateway)->toBeInstanceOf(SubmitsDisputeEvidence::class)
        ->and($gateway)->toBeInstanceOf(ConcedesDisputes::class)
        ->and($gateway)->toBeInstanceOf(ReadsDisputeCases::class);
});

it('delegates submitEvidence to the evidence update endpoint', function () {
    $api = stripeDisputeGatewayFakeApi(stripeDisputeSuiteDispute());

    $result = stripeDisputeSuiteGateway()->submitEvidence(new DisputeEvidenceCommand(
        gatewayId: GatewayId::generate(),
        disputeReference: 'dp_1QkDisputeCaseAlpha',
        evidence: [],
        submit: false,
    ));

    expect($result->success)->toBeTrue()
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/disputes/dp_1QkDisputeCaseAlpha');
});

it('delegates concede to the close endpoint', function () {
    $api = stripeDisputeGatewayFakeApi(stripeDisputeSuiteDispute('lost'));

    $result = stripeDisputeSuiteGateway()->concede(new DisputeConcessionCommand(
        gatewayId: GatewayId::generate(),
        disputeReference: 'dp_1QkDisputeCaseAlpha',
    ));

    expect($result->success)->toBeTrue()
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/disputes/dp_1QkDisputeCaseAlpha/close');
});

it('delegates readDisputeCase to the dispute retrieve endpoint', function () {
    $api = stripeDisputeGatewayFakeApi(stripeDisputeSuiteDispute());

    $reading = stripeDisputeSuiteGateway()->readDisputeCase(new DisputeCaseQuery(
        gatewayId: GatewayId::generate(),
        disputeReference: 'dp_1QkDisputeCaseAlpha',
    ));

    expect($reading->awaitingResponse)->toBeTrue()
        ->and($api->calls[0]['url'])->toBe('https://api.stripe.com/v1/disputes/dp_1QkDisputeCaseAlpha');
});

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});
