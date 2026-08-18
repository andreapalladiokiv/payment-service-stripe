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

    /**
     * Where Stripe sends the cardholder back after an authentication it hosts.
     *
     * The parameter is omnipay's own — the setter comes from
     * {@see \Omnipay\Common\Message\AbstractRequest::setReturnUrl} and takes it off the
     * request bag like any other. This reads it rather than overriding `getReturnUrl()`,
     * which omnipay annotates `@return string` although it returns whatever the bag holds:
     * a caller that left it out and a caller that passed an empty string are the one
     * answer here, and it is null.
     *
     * Optional, and the caller's to decide — a hosted checkout returns to its own page, an
     * API caller may have nowhere to return to at all. Supplying it is also what makes
     * Stripe answer a 3DS card with `next_action.redirect_to_url` rather than
     * `use_stripe_sdk`: the second conducts the authentication inside Stripe.js, which this
     * package does not drive, and there is no address to hand anyone.
     */
    protected function normalizedReturnUrl(): ?string
    {
        $url = $this->getParameter('returnUrl');

        return is_string($url) && $url !== '' ? $url : null;
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
     * `$scope` exists because **Stripe binds an idempotency key to the first
     * endpoint it was used on** and rejects it everywhere else:
     *
     * > Keys for idempotent requests can only be used for the same endpoint
     * > they were first used for.
     *
     * A `sendData()` that calls two endpoints therefore cannot hand both the
     * bare `clientUniqueId` — the second call fails, and because the id is
     * stable the failure is permanent: every retry burns on the same
     * collision and the operation can never complete. Pass a distinct scope
     * per endpoint in that case; the key stays derived from the same id, so
     * a retry still lands on the same key and idempotency is preserved.
     *
     * Single-endpoint operations pass nothing and keep the bare id — changing
     * their key would orphan whatever Stripe already cached against it.
     *
     * @param  ?string  $scope  endpoint discriminator, for operations that call more than one
     * @return array{idempotency_key?: non-empty-string} the shape Stripe's services declare
     *   for their per-request options argument; a bare `array` made every call site an
     *   unverifiable coercion
     */
    protected function stripeOpts(?string $scope = null): array
    {
        $key = $this->getClientUniqueId();

        if ($key === null || $key === '') {
            return [];
        }

        return ['idempotency_key' => $scope === null ? $key : $key.':'.$scope];
    }
}
