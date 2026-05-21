<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

final class VoidResponse extends StripeResponse
{
    public function isSuccessful(): bool
    {
        return ($this->data['status'] ?? null) === 'canceled';
    }
}
