<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

/**
 * The deployment's Stripe configuration, as opposed to anything a payment carries.
 *
 * `authenticationUrl` and `returnUrl` are one value per deployment — where a cardholder comes back
 * to after a step-up — which is exactly what separates them from a command's fields. They used to
 * reach a request as two more keys in the same array as the amount, and each needed a setter on
 * the request purely so Omnipay's initializer would not silently drop it on the way.
 */
final readonly class StripeSettings
{
    public function __construct(
        public string $apiKey = '',
        public ?string $authenticationUrl = null,
        public ?string $returnUrl = null,
    ) {}

    /**
     * Trailing slash removed: the URL is concatenated with a path, and Stripe rejects the double
     * separator that a stored value ending in `/` would produce.
     */
    public function normalizedAuthenticationUrl(): ?string
    {
        return $this->authenticationUrl !== null && $this->authenticationUrl !== ''
            ? rtrim($this->authenticationUrl, '/')
            : null;
    }

    public function normalizedReturnUrl(): ?string
    {
        return $this->returnUrl !== null && $this->returnUrl !== '' ? $this->returnUrl : null;
    }
}
