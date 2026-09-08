<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Override;
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

    #[Override]
    public function getData(): array
    {
        // Read off the billing address rather than five discrete keys. Those keys had no
        // setters, so omnipay dropped every one of them and this request created customers
        // carrying an email and nothing else — while the caller was already handing over a
        // whole BillingAddress that had nowhere to land.
        $address = $this->getBillingAddress();
        $email = $this->getEmail() !== '' ? $this->getEmail() : (string) ($address?->email ?? '');

        return array_filter([
            'email' => $email,
            'address' => $this->formatCustomerAddress($address),
        ]);
    }

    #[Override]
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
