<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;

/**
 * Creates a Stripe Customer and answers with the `cus_xxx` as the reference.
 *
 * Not one of the gateway's roles — nothing routes to it. It exists because a PaymentMethod with
 * no Customer is single-use, so {@see StripeGateway} mints one when an instrument arrives with
 * nobody to belong to. That is the only caller.
 */
final class CreateCustomer
{
    use StripeRequestParameters;

    public function __construct(
        private readonly StripeSettings $settings,
        private readonly string $email = '',
        private readonly ?BillingAddress $billingAddress = null,
    ) {}

    /**
     * @return array{email?: string, address?: array{line1?: string, city?: string, country?: string, postal_code?: string, state?: string}}
     */
    public function payload(): array
    {
        // Read off the billing address rather than five discrete keys. Those keys had no
        // setters, so omnipay dropped every one of them and this operation created customers
        // carrying an email and nothing else — while the caller was already handing over a
        // whole BillingAddress that had nowhere to land.
        $address = $this->billingAddress;
        $email = $this->email !== '' ? $this->email : (string) ($address?->email ?? '');

        return array_filter([
            'email' => $email,
            'address' => $this->formatCustomerAddress($address),
        ]);
    }

    public function create(): GatewayResult
    {
        try {
            $stripe = new StripeClient($this->settings->apiKey);

            $customer = $stripe->customers->create($this->payload());

            return GatewayResult::succeeded($customer->id);
        } catch (ApiErrorException $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }
}
