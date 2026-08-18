<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Override;

final class PurchaseResponse extends StripeResponse
{
    /**
     * A charge succeeded when the money has moved. See {@see AuthorizeResponse} for why
     * the presence of an id does not answer that.
     *
     * The hosted Checkout branch reports no status because it has no payment intent yet —
     * a Session is a promise of one. It carries a redirect challenge instead, which is
     * what the caller acts on, so it is not asked this question.
     */
    #[Override]
    public function isSuccessful(): bool
    {
        return parent::isSuccessful() && ($this->data['status'] ?? null) === 'succeeded';
    }
}
