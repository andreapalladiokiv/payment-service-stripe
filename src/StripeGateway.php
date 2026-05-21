<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\AbstractRequest;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;

final class StripeGateway extends AbstractGateway implements Gateway
{
    private ?CustomerRepository $customerRepository = null;

    public function getName(): string
    {
        return 'stripe';
    }

    public function setCustomerRepository(CustomerRepository $repository): void
    {
        $this->customerRepository = $repository;
    }

    public function getDefaultParameters(): array
    {
        return ['apiKey' => ''];
    }

    public function getApiKey(): string
    {
        return $this->getParameter('apiKey') ?? '';
    }

    public function setApiKey(string $value): static
    {
        return $this->setParameter('apiKey', $value);
    }

    public function createCustomer(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(CreateCustomerRequest::class, $parameters);
    }

    public function updateCustomer(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(UpdateCustomerRequest::class, $parameters);
    }

    public function createCard(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(CreateCardRequest::class, $parameters);
    }

    public function createPaymentMethod(array $parameters = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $parameters['gateway'] ?? null,
            $parameters['instrument'] ?? null,
            $parameters['billingAddress'] ?? null,
        );
        if ($customerReference !== null) {
            $parameters['customerReference'] = $customerReference;
        }

        return $this->createRequest(CreatePaymentMethodRequest::class, $parameters);
    }

    public function purchase(array $parameters = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $parameters['gateway'] ?? null,
            $parameters['instrument'] ?? null,
            $parameters['billingAddress'] ?? null,
        );
        if ($customerReference !== null) {
            $parameters['customerReference'] = $customerReference;
        }

        return $this->createRequest(PurchaseRequest::class, $parameters);
    }

    public function authorize(array $parameters = []): AbstractRequest
    {
        $customerReference = $this->resolveCustomerReference(
            $parameters['gateway'] ?? null,
            $parameters['instrument'] ?? null,
            $parameters['billingAddress'] ?? null,
        );
        if ($customerReference !== null) {
            $parameters['customerReference'] = $customerReference;
        }

        return $this->createRequest(AuthorizeRequest::class, $parameters);
    }

    public function capture(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(CaptureRequest::class, $parameters);
    }

    public function refund(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(RefundRequest::class, $parameters);
    }

    public function retryRefund(array $parameters = []): AbstractRequest
    {
        // Stripe's Refund API can only return funds along the original
        // PaymentIntent — there is no public primitive to redirect a
        // refund onto a different card. The closest alternative is
        // Stripe Issuing (separate product, requires onboarding) and is
        // intentionally out of scope here.
        throw new RuntimeException(
            'Stripe does not support refunding to an alternative card; '
            .'the refund must return to the original payment source.',
        );
    }

    public function void(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(VoidRequest::class, $parameters);
    }

    public function issueVirtualCard(array $parameters = []): AbstractRequest
    {
        throw new RuntimeException('Stripe does not support virtual card issuance.');
    }

    public function updateVirtualCard(array $parameters = []): AbstractRequest
    {
        throw new RuntimeException('Stripe does not support virtual card update.');
    }

    public function terminateVirtualCard(array $parameters = []): AbstractRequest
    {
        throw new RuntimeException('Stripe does not support virtual card termination.');
    }

    /**
     * Finds the customer reference linked to this instrument, or creates a
     * new Stripe customer and links it. Returns null if customer lookup
     * isn't applicable (no repository, no instrument, no billing address).
     */
    private function resolveCustomerReference(
        ?GatewayCredential $gateway,
        ?PaymentInstrument $instrument,
        ?BillingAddress $billingAddress,
    ): ?string {
        if ($this->customerRepository === null || $gateway === null || $instrument === null) {
            return null;
        }

        $gatewayId = $gateway->getId();

        $existing = $this->customerRepository->findByInstrument($gatewayId, $instrument);
        if ($existing !== null) {
            return $existing;
        }

        if ($billingAddress === null || $billingAddress->email === null) {
            return null;
        }

        $response = $this->createCustomer(['billingAddress' => $billingAddress])->send();

        if (! $response->isSuccessful()) {
            throw new RuntimeException("Stripe createCustomer failed: {$response->getMessage()}");
        }

        $customerReference = $response->getTransactionReference()
            ?? throw new RuntimeException('Stripe createCustomer returned no reference.');

        $this->customerRepository->saveAndAttach($gatewayId, $instrument, $customerReference);

        return $customerReference;
    }
}
