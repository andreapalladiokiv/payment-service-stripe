<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Stripe\StripeResponse;

function makeStripeResponse(array $data): StripeResponse
{
    $request = Mockery::mock(RequestInterface::class);

    return new StripeResponse($request, $data);
}

it('implements CardChecksProvider', function () {
    expect(makeStripeResponse([]))->toBeInstanceOf(CardChecksProvider::class);
});

it('returns CheckResult enums when present in data', function () {
    $response = makeStripeResponse([
        'reference' => 'pi_123',
        'address_line_check' => 'pass',
        'postal_code_check' => 'fail',
        'cvc_check' => 'unavailable',
    ]);

    expect($response->getAddressLineCheck())->toBe(CheckResult::Pass)
        ->and($response->getPostalCodeCheck())->toBe(CheckResult::Fail)
        ->and($response->getCvcCheck())->toBe(CheckResult::Unavailable);
});

it('returns null when check keys are absent', function () {
    $response = makeStripeResponse(['reference' => 'pi_123']);

    expect($response->getAddressLineCheck())->toBeNull()
        ->and($response->getPostalCodeCheck())->toBeNull()
        ->and($response->getCvcCheck())->toBeNull();
});

it('treats Unchecked as a real signal (distinct from absence)', function () {
    $response = makeStripeResponse([
        'reference' => 'pi_123',
        'address_line_check' => 'unchecked',
    ]);

    expect($response->getAddressLineCheck())->toBe(CheckResult::Unchecked)
        ->and($response->getPostalCodeCheck())->toBeNull();
});
