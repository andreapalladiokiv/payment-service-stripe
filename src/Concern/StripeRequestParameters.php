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

    /**
     * Omnipay applies an option only when a matching `set…()` exists
     * ({@see \Omnipay\Common\Helper::initialize}), and this one had none — so
     * `billingAddress` was dropped from every Stripe request that asked for it.
     * PurchaseRequest, AuthorizeRequest and CreatePaymentMethodRequest all read the
     * parameter to build `billing_details`, which means every Stripe payment went out
     * with no billing details at all: nothing for the issuer to run AVS or a postal-code
     * check against, and nothing to explain why those checks came back Unchecked.
     */
    public function setBillingAddress(?BillingAddress $value): static
    {
        return $this->setParameter('billingAddress', $value);
    }

    public function getBillingAddress(): ?BillingAddress
    {
        $address = $this->getParameter('billingAddress');

        return $address instanceof BillingAddress ? $address : null;
    }

    /**
     * The `address` block of a Stripe Customer, or null when there is nothing to say.
     *
     * Null rather than an empty array on purpose: Stripe reads `address: {}` as an
     * instruction to CLEAR the stored address, so sending one for a customer we simply know
     * nothing new about would erase what is already there.
     *
     * @return array<string, string>|null
     */
    protected function formatCustomerAddress(?BillingAddress $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return array_filter([
            'line1' => $address->line,
            'city' => $address->city,
            'country' => (string) $address->country,
            'postal_code' => $address->postalCode,
            'state' => $address->state !== null ? (string) $address->state : null,
        ]) ?: null;
    }

    /**
     * `static`, not `self`: this overrides {@see \Omnipay\Common\Message\AbstractRequest::setMoney},
     * which is annotated `@return $this`. Naming the using class instead would promise a
     * fixed type where the parent promises the called one.
     */
    public function setMoney(Money $value): static
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
    /**
     * @return array{idempotency_key?: non-empty-string} the shape Stripe's services declare
     *   for their per-request options argument; a bare `array` made every call site an
     *   unverifiable coercion
     */
    protected function stripeOpts(): array
    {
        $key = $this->getClientUniqueId();

        return $key === null || $key === '' ? [] : ['idempotency_key' => $key];
    }
}
