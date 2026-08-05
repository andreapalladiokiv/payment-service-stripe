<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Override;

final class VoidResponse extends StripeResponse
{
    #[Override]
    public function isSuccessful(): bool
    {
        return ($this->data['status'] ?? null) === 'canceled';
    }
}
