<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Money\Money;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Stripe\Concern\ExtractsCardChecks;
use Techork\PaymentService\Stripe\Concern\ExtractsConvertedAmount;
use Techork\PaymentService\Stripe\Concern\ReadsPaymentIntentOutcome;

/**
 * Reads a Stripe PaymentIntent and says what it means for a payment.
 *
 * One mapping where there used to be three: the request assembled an array of extracted fields, a
 * response class wrapped it and answered `isSuccessful()`/`getChallenge()`/`getCvcCheck()` through
 * interfaces, and a shared assembler read those back to build the result. Every hop was there so a
 * generic folder could interrogate a homogenised response — but each provider had to write that
 * response itself, so nothing was ever actually shared. The provider knows what it got; it says so
 * directly.
 *
 * `$expected` is the status that counts as done for the operation being performed: an
 * authorization is finished at `requires_capture`, a charge at `succeeded`. Anything else with no
 * challenge to present is a payment that went nowhere a caller can act on, and
 * {@see ReadsPaymentIntentOutcome} turns it into a message that names the shape rather than a bare
 * failure.
 */
final class PaymentIntentOutcome
{
    use ExtractsCardChecks;
    use ExtractsConvertedAmount;
    use ReadsPaymentIntentOutcome;

    public function map(PaymentIntent $paymentIntent, ?Challenge $challenge, string $expected): AuthorizationResult
    {
        $unusable = $this->explainUnusableOutcome($paymentIntent, $challenge, $expected);

        if ($unusable !== null) {
            return AuthorizationResult::failed($unusable);
        }

        $result = $challenge !== null
            ? AuthorizationResult::requiresAction($paymentIntent->id, $challenge)
            : AuthorizationResult::succeeded($paymentIntent->id);

        return $this->withChecks($result, $paymentIntent)
            ->withConvertedAmount($challenge === null ? $this->convertedAmountOf($paymentIntent) : null)
            ->withMetadata(self::openingMetadata($paymentIntent));
    }

    /**
     * Which transaction OPENED the payment intent, recorded because `reference` cannot answer it
     * later: the row is overwritten on transition, so once a capture lands it holds the settle
     * reference. It is what
     * {@see \Techork\PaymentService\Laravel\Port\RebillingCreateAdapter} reads back to anchor a
     * series onto its genesis authorization.
     *
     * Only the opening operations reach here — capture, cancel and refund return a bare
     * {@see \Techork\PaymentService\Gateway\Contract\GatewayResult} — so it is structurally
     * impossible for a settle to write this key and bury the anchor under its own reference.
     * That guarantee used to live in `ResultAssembler::openingMetadata()`, which only the
     * opening operations called; it lives here now, in the mapping only they use.
     *
     * @return array<string, mixed>
     */
    private static function openingMetadata(PaymentIntent $paymentIntent): array
    {
        $reference = $paymentIntent->id;

        return $reference === '' ? [] : ['opening_transaction_reference' => $reference];
    }

    private function withChecks(AuthorizationResult $result, PaymentIntent $paymentIntent): AuthorizationResult
    {
        // `??` rather than a bare read, for {@see ExtractsConvertedAmount}'s reason: a missing
        // property on a Stripe object reaches `StripeObject::__get`, which logs
        // "Stripe Notice: Undefined property of …" before answering null. An intent with no
        // payment method is ordinary here — a hosted session, an intent that never got one — so
        // reading it bare wrote a line of noise for each of them.
        $candidate = $paymentIntent->payment_method ?? null;
        $method = $candidate instanceof PaymentMethod ? $candidate : null;
        $checks = $this->extractStripeChecks($method);

        return $result->withChecks(
            self::check($checks['address_line_check'] ?? null),
            self::check($checks['postal_code_check'] ?? null),
            self::check($checks['cvc_check'] ?? null),
        );
    }

    private function convertedAmountOf(PaymentIntent $paymentIntent): ?Money
    {
        return $this->extractConvertedAmount($paymentIntent);
    }

    private static function check(?string $raw): ?CheckResult
    {
        return $raw === null ? null : CheckResult::from($raw);
    }
}
