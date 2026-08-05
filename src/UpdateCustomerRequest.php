<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Override;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Omnipay\Common\Message\AbstractRequest;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final class UpdateCustomerRequest extends AbstractRequest
{
    use StripeRequestParameters;

    public function getEmail(): string
    {
        return (string) ($this->getParameter('email') ?? '');
    }

    public function setEmail(string $value): static
    {
        return $this->setParameter('email', $value);
    }

    public function getCustomerReference(): string
    {
        return (string) ($this->getParameter('customerReference') ?? '');
    }

    public function setCustomerReference(string $value): static
    {
        return $this->setParameter('customerReference', $value);
    }

    #[Override]
    public function getData(): array
    {
        // Same unreachable keys as CreateCustomerRequest had, with the same consequence:
        // getData() always returned [] and this request could only ever send an empty update.
        $address = $this->getBillingAddress();

        return array_filter([
            'email' => $this->getEmail() !== '' ? $this->getEmail() : (string) ($address?->email ?? ''),
            'address' => $this->formatCustomerAddress($address),
        ]);
    }

    #[Override]
    public function sendData($data): CreateCustomerResponse
    {
        try {
            $customerReference = $this->getCustomerReference();

            // Refused here rather than by the SDK. `customers->update(null, …)` throws
            // Stripe\Exception\InvalidArgumentException, which is NOT an ApiErrorException,
            // so it escaped the catch below and left the request instead of becoming the
            // failed response every other path produces — an unhandled error where the
            // router expects a recorded failure.
            if ($customerReference === '') {
                return new CreateCustomerResponse($this, [
                    'reference' => null,
                    'error' => 'No Stripe customer reference was supplied to update.',
                ]);
            }

            $stripe = new StripeClient($this->getApiKey());

            $stripe->customers->update($customerReference, $data);

            return new CreateCustomerResponse($this, [
                'reference' => $customerReference,
                'error' => null,
            ]);
        } catch (ApiErrorException $e) {
            return new CreateCustomerResponse($this, [
                'reference' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
