<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Omnipay\Common\Message\AbstractRequest;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Creates a Stripe Customer.
 * Expects: email (string).
 * Returns cus_xxx as the transaction reference.
 */
final class CreateCustomerRequest extends AbstractRequest
{
    use StripeRequestParameters;

    public function getData(): array
    {
        return array_filter([
            'email' => $this->getEmail(),
            'address' => array_filter([
                'line1' => $this->getParameter('address'),
                'city' => $this->getParameter('city'),
                'country' => $this->getParameter('country'),
                'postal_code' => $this->getParameter('postal_code'),
                'state' => $this->getParameter('state'),
            ]) ?: null,
        ]);
    }

    public function sendData($data): CreateCustomerResponse
    {
        try {
            $stripe = new StripeClient($this->getApiKey());

            $customer = $stripe->customers->create($data);

            return new CreateCustomerResponse($this, [
                'reference' => $customer->id,
                'error' => null,
            ]);
        } catch (ApiErrorException $e) {
            return new CreateCustomerResponse($this, [
                'reference' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getEmail(): string
    {
        return $this->getParameter('email') ?? '';
    }

    public function setEmail(string $value): self
    {
        return $this->setParameter('email', $value);
    }
}
