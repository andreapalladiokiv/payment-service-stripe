<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;

/**
 * Creates a Stripe Customer and answers with the `cus_xxx` as the reference.
 *
 * Reached only from {@see StripeGateway::registerCustomer()}, which is now the one operation
 * that creates one. It used to be reached from resolution as well, on every payment, with an
 * identity assembled out of whatever `BillingAddress` had ridden along — so a charge could mint a
 * person. The identity is passed in now, by the caller that holds the customer.
 *
 * `$identity` supplies the person and `$billingAddress` supplies the address. They are separate
 * arguments and not one, because the two answer different questions and Stripe stores both.
 */
final class CreateCustomer
{
    use StripeRequestParameters;

    public function __construct(
        private readonly StripeSettings $settings,
        private readonly ?CustomerIdentity $identity = null,
        private readonly ?BillingAddress $billingAddress = null,
    ) {}

    /**
     * @return array{name?: string, email?: string, address?: array{line1?: string, city?: string, country?: string, postal_code?: string, state?: string}}
     */
    public function payload(): array
    {
        // Read off the billing address rather than five discrete keys. Those keys had no
        // setters, so omnipay dropped every one of them and this operation created customers
        // carrying an email and nothing else — while the caller was already handing over a
        // whole BillingAddress that had nowhere to land.
        $address = $this->billingAddress;
        $identity = $this->identity;

        // The identity answers first and the address is the fallback, which is the same order
        // every provider here now reads them in: the address is where the payer's name and email
        // used to be kept, one copy per card, so it is still the honest answer when nobody has
        // been named — and it stops being consulted the moment somebody has.
        $email = (string) ($identity?->email ?? $address?->email ?? '');
        $name = trim(($identity->firstName ?? $address?->firstName ?? '').' '.($identity->lastName ?? $address?->lastName ?? ''));

        return array_filter([
            'name' => $name,
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
