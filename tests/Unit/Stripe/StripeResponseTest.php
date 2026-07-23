<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Gateway\Contract\ConvertedAmountProvider;
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

it('implements ConvertedAmountProvider', function () {
    expect(makeStripeResponse([]))->toBeInstanceOf(ConvertedAmountProvider::class);
});

it('surfaces the FX-settled convertedAmount carried in data', function () {
    $converted = new Money(5712, new Currency('USD'));
    $response = makeStripeResponse(['reference' => 'pi_123', 'converted_amount' => $converted]);

    expect($response->getConvertedAmount())->toBe($converted);
});

it('returns null convertedAmount when the key is absent', function () {
    expect(makeStripeResponse(['reference' => 'pi_123'])->getConvertedAmount())->toBeNull();
});
