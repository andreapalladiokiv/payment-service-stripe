<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Money\Money;
use Omnipay\Common\Message\AbstractResponse;
use Override;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Gateway\Contract\ChallengeProvider;
use Techork\PaymentService\Gateway\Contract\ConvertedAmountProvider;

class StripeResponse extends AbstractResponse implements CardChecksProvider, ChallengeProvider, ConvertedAmountProvider
{
    #[Override]
    public function isSuccessful(): bool
    {
        return isset($this->data['reference']) && $this->data['reference'] !== null;
    }

    #[Override]
    public function getTransactionReference(): ?string
    {
        return $this->data['reference'] ?? null;
    }

    #[Override]
    public function getMessage(): ?string
    {
        return $this->data['error'] ?? null;
    }

    #[Override]
    public function getChallenge(): ?Challenge
    {
        return $this->data['challenge'] ?? null;
    }

    #[Override]
    public function getConvertedAmount(): ?Money
    {
        return $this->data['converted_amount'] ?? null;
    }

    #[Override]
    public function getAddressLineCheck(): ?CheckResult
    {
        return $this->resolveCheck('address_line_check');
    }

    #[Override]
    public function getPostalCodeCheck(): ?CheckResult
    {
        return $this->resolveCheck('postal_code_check');
    }

    #[Override]
    public function getCvcCheck(): ?CheckResult
    {
        return $this->resolveCheck('cvc_check');
    }

    private function resolveCheck(string $key): ?CheckResult
    {
        $raw = $this->data[$key] ?? null;

        return $raw === null ? null : CheckResult::from($raw);
    }
}
