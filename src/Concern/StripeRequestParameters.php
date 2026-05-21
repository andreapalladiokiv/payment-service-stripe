<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Concern;

use Money\Money;

trait StripeRequestParameters
{
    public function getApiKey(): string
    {
        return $this->getParameter('apiKey') ?? '';
    }

    public function setApiKey(string $value): self
    {
        return $this->setParameter('apiKey', $value);
    }

    public function setMoney(Money $value): self
    {
        return $this->setParameter('money', $value);
    }

    public function getClientUniqueId(): ?string
    {
        return $this->getParameter('clientUniqueId');
    }

    public function setClientUniqueId(?string $value): self
    {
        return $this->setParameter('clientUniqueId', $value);
    }

    /**
     * Builds the Stripe SDK opts array, populating `idempotency_key` only
     * when the caller passed a stable id. Ops that expose no idempotency
     * key (terminal one-shot ops) get an empty array, matching the Stripe
     * SDK's default behavior.
     *
     * @return array<string, mixed>
     */
    protected function stripeOpts(): array
    {
        $key = $this->getClientUniqueId();

        return $key === null || $key === '' ? [] : ['idempotency_key' => $key];
    }
}
