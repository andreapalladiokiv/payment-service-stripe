<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;

/**
 * Writes an email and an address onto a Stripe Customer that already exists.
 *
 * The reference is public because it is what the result echoes back: an update cannot change a
 * customer's id, so the caller's own id is the reference, and the answer proves nothing about
 * what Stripe stored.
 */
final class UpdateCustomer
{
    use StripeRequestParameters;

    public function __construct(
        private readonly StripeSettings $settings,
        public readonly string $customerReference = '',
        private readonly string $email = '',
        private readonly ?Customer $customer = null,
    ) {}

    /**
     * @return array{email?: string, address?: array{line1?: string, city?: string, country?: string, postal_code?: string, state?: string}}
     */
    public function payload(): array
    {
        // Same unreachable keys as CreateCustomer had, with the same consequence:
        // this used to build [] always, and the operation could only ever send an empty update.
        //
        // The explicit `$email` still wins over the customer's, because that is the argument's
        // whole purpose: correcting an email at Stripe without restating who the person is.
        return array_filter([
            'email' => $this->email !== '' ? $this->email : (string) ($this->customer?->identity->email ?? ''),
            'address' => $this->formatCustomerAddress($this->customer?->billingAddress),
        ]);
    }

    public function update(): GatewayResult
    {
        try {
            $customerReference = $this->customerReference;

            // Refused here rather than by the SDK. `customers->update(null, …)` throws
            // Stripe\Exception\InvalidArgumentException, which is NOT an ApiErrorException,
            // so it escaped the catch below and left the operation instead of becoming the
            // failed result every other path produces — an unhandled error where the
            // router expects a recorded failure.
            if ($customerReference === '') {
                return GatewayResult::failed('No Stripe customer reference was supplied to update.');
            }

            $stripe = new StripeClient($this->settings->apiKey);

            $stripe->customers->update($customerReference, $this->payload());

            return GatewayResult::succeeded($customerReference);
        } catch (ApiErrorException $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }
}
