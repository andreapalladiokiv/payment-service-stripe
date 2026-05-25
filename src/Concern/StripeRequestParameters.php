<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Concern;

use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;

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

    public function setStatementDescription(?string $value): self
    {
        return $this->setParameter('statementDescription', $value);
    }

    public function getStatementDescription(): ?string
    {
        return $this->getParameter('statementDescription');
    }

    protected function formatBillingDetails(?BillingAddress $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $name = trim($address->firstName.' '.$address->lastName);

        $address1 = $address->line;
        $address2 = $address->lineExtra !== '' ? $address->lineExtra : null;

        return array_filter([
            'name' => $name !== '' ? $name : null,
            'email' => $address->email ? (string) $address->email : null,
            'phone' => $address->phone ? (string) $address->phone : null,
            'address' => array_filter([
                'line1' => $address1,
                'line2' => $address2,
                'city' => $address->city,
                'state' => $address->state ? (string) $address->state : null,
                'postal_code' => $address->postalCode,
                'country' => (string) $address->country,
            ], static fn ($v) => $v !== null && $v !== ''),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
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
