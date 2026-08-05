<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Gateway\Exception\IncompleteAuthentication;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Stripe\Concern\FormatsThreeDS;

/**
 * The three request classes that forward an authentication to Stripe had no test over this
 * block at all, which is how they came to send explicit `null`s for keys Stripe declares as
 * optional-but-string. Psalm surfaced one of the three; the other two build the same shape
 * through a looser SDK signature and would have stayed invisible.
 *
 * Exercised through the trait rather than through the request classes: those need an
 * omnipay request, an API key and a live SetupIntent call to reach the same lines, and none
 * of that is what is in question here.
 */
function stripeThreeDSFormatter(?ThreeDSResult $result): object
{
    return new class($result)
    {
        use FormatsThreeDS;

        public function __construct(private readonly ?ThreeDSResult $threeDS) {}

        public function getThreeDS(): ?ThreeDSResult
        {
            return $this->threeDS;
        }

        /** @return array<string, mixed>|null */
        public function format(): ?array
        {
            return $this->formatThreeDS();
        }
    };
}

function stripeThreeDSResult(
    ?string $authenticationValue = 'cavv-base64',
    ?ThreeDSVersion $version = ThreeDSVersion::V220,
    ?ECICode $eci = ECICode::VisaSuccessful,
): ThreeDSResult {
    return new ThreeDSResult(
        ThreeDSStatus::Successful,
        $authenticationValue,
        $eci,
        'ds-trans-1',
        'acs-trans-1',
        $version,
    );
}

it('sends nothing when the operation carries no authentication', function () {
    expect(stripeThreeDSFormatter(null)->format())->toBeNull();
});

it('maps a complete attestation onto the card options block', function () {
    expect(stripeThreeDSFormatter(stripeThreeDSResult())->format())->toBe([
        'card' => [
            'three_d_secure' => [
                'cryptogram' => 'cavv-base64',
                'transaction_id' => 'ds-trans-1',
                'ares_trans_status' => 'Y',
                'version' => '2.2.0',
                'electronic_commerce_indicator' => '05',
            ],
        ],
    ]);
});

it('omits the optional members it does not have rather than sending null', function () {
    // The point of the change. Stripe declares `version?: string`, not `version?: ?string`,
    // so a null is a wrong-typed value and not an absent one.
    $block = stripeThreeDSFormatter(stripeThreeDSResult(version: null, eci: null))->format();

    expect($block['card']['three_d_secure'])->toBe([
        'cryptogram' => 'cavv-base64',
        'transaction_id' => 'ds-trans-1',
        'ares_trans_status' => 'Y',
    ])
        ->and($block['card']['three_d_secure'])->not->toHaveKeys(['version', 'electronic_commerce_indicator']);
});

it('refuses an attestation with no cryptogram', function (?string $authenticationValue) {
    // ConnexPay was measured to accept this shape and process it as unauthenticated while
    // reporting nothing. Refusing keeps both providers answering the same input alike.
    expect(fn () => stripeThreeDSFormatter(stripeThreeDSResult($authenticationValue))->format())
        ->toThrow(IncompleteAuthentication::class, 'missing cryptogram');
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('refuses it as a structural failure, not as a decline', function () {
    $thrown = null;

    try {
        stripeThreeDSFormatter(stripeThreeDSResult(null))->format();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    // The marker is what makes the router rethrow instead of folding this into a fake
    // acquirer decline, which would tell the caller the issuer said no. Asserted with a
    // catch rather than toThrow(), which only understands class names and would read a
    // marker interface as an expected message.
    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class);
});
