<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Override;

final class AuthorizeResponse extends StripeResponse
{
    /**
     * An authorization succeeded when the money is held, and Stripe says so with one
     * status. The inherited "there is a reference" test cannot answer this: a payment
     * intent has an id in every state, `requires_action` included, so a card that asked
     * for 3DS was reported as an authorization and the caller went on to capture funds
     * nobody was holding.
     */
    #[Override]
    public function isSuccessful(): bool
    {
        return parent::isSuccessful() && ($this->data['status'] ?? null) === 'requires_capture';
    }
}
