<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Omnipay\Common\Message\AbstractRequest;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final class UpdateCustomerRequest extends AbstractRequest
{
    use StripeRequestParameters;

    public function getData(): array
    {
        return array_filter([
            'email' => $this->getParameter('email'),
            'address' => array_filter([
                'line1' => $this->getParameter('address'),
                'city' => $this->getParameter('city'),
                'country' => $this->getParameter('country'),
                'postal_code' => $this->getParameter('postal_code'),
                'state' => $this->getParameter('state'),
            ]),
        ]);
    }

    public function sendData($data): CreateCustomerResponse
    {
        try {
            $stripe = new StripeClient($this->getApiKey());
            $customerReference = $this->getParameter('customerReference');

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
