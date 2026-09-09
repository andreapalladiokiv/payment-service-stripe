<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;

/**
 * Creates a Stripe Customer and answers with the `cus_xxx` as the reference.
 *
 * Reached only from {@see StripeGateway::registerCustomer()}, which is now the one operation
 * that creates one. It used to be reached from resolution as well, on every payment, with an
 * identity assembled out of whatever billing address had ridden along — so a charge could mint a
 * person. The {@see Customer} is passed in now, by the caller that holds it.
 *
 * The person and the address stay distinct inside it, because the two answer different questions
 * and Stripe stores both. What they are no longer is separately omissible: they were two optional
 * arguments here, and either being absent is how a customer got assembled out of fragments.
 */
final class CreateCustomer
{
    use StripeRequestParameters;

    public function __construct(
        private readonly StripeSettings $settings,
        private readonly Customer $customer,
    ) {}

    /**
     * @return array{name?: string, email?: string, address?: array{line1?: string, city?: string, country?: string, postal_code?: string, state?: string}}
     */
    public function payload(): array
    {
        // Read off the customer rather than five discrete keys. Those keys had no setters, so
        // omnipay dropped every one of them and this operation created customers carrying an
        // email and nothing else — while the caller was already handing over the address and the
        // name, which had nowhere to land.
        $identity = $this->customer->identity;

        return array_filter([
            'name' => trim($identity->firstName.' '.$identity->lastName),
            'email' => (string) $identity->email,
            'address' => $this->formatCustomerAddress($this->customer->billingAddress),
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
