<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Omnipay\Common\Message\AbstractResponse;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Gateway\Contract\ChallengeProvider;

class StripeResponse extends AbstractResponse implements CardChecksProvider, ChallengeProvider
{
    public function isSuccessful(): bool
    {
        return isset($this->data['reference']) && $this->data['reference'] !== null;
    }

    public function getTransactionReference(): ?string
    {
        return $this->data['reference'] ?? null;
    }

    public function getMessage(): ?string
    {
        return $this->data['error'] ?? null;
    }

    public function getChallenge(): ?Challenge
    {
        return $this->data['challenge'] ?? null;
    }

    public function getAddressLineCheck(): ?CheckResult
    {
        return $this->resolveCheck('address_line_check');
    }

    public function getPostalCodeCheck(): ?CheckResult
    {
        return $this->resolveCheck('postal_code_check');
    }

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
