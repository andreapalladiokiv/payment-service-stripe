<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Override;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
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
        // A `CustomerIdentity` when the caller knows whose customer this is, and an address
        // otherwise. The second is the older path and the weaker one: it names a Stripe Customer
        // after whatever address rode along with one payment, so the same person paying from two
        // addresses becomes two customers. It stays for callers that have not started naming the
        // customer yet.
        //
        // Read off objects rather than five discrete keys, because those keys had no setters and
        // omnipay dropped every one of them: this request used to create customers carrying an
        // email and nothing else, while the caller was handing over a whole address that had
        // nowhere to land.
        $identity = $this->getCustomerIdentity();
        $address = $this->getBillingAddress();

        if ($identity !== null) {
            return array_filter([
                'name' => trim($identity->firstName.' '.$identity->lastName),
                'email' => $identity->email ? (string) $identity->email : null,
                'phone' => $identity->phone ? (string) $identity->phone : null,
                'address' => $this->formatCustomerAddress($address),
            ]);
        }

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

    /**
     * Ours is not sent, and there is nothing to translate.
     *
     * Stripe assigns the id — the `cus_…` comes back on the response and is what
     * `GatewayCustomerRepository` stores against our customer. That is the opposite of Nuvei, where
     * the id is ours to choose and `userTokenId` has to carry it, so
     * {@see \Techork\PaymentService\Nuvei\CreateCustomerRequest::setCustomerId()} exists there
     * and here it deliberately does not: a setter that accepted and ignored our id would read as
     * if Stripe were being told something it never receives.
     */
    public function setCustomerIdentity(?CustomerIdentity $value): self
    {
        return $this->setParameter('customerIdentity', $value);
    }

    public function getCustomerIdentity(): ?CustomerIdentity
    {
        $identity = $this->getParameter('customerIdentity');

        return $identity instanceof CustomerIdentity ? $identity : null;
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
