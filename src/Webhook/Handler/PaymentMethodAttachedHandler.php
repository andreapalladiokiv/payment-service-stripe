<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Webhook\Handler;

use Override;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayPaymentMethodRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Stripe\Event;
use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Stripe `payment_method.attached`: a card was attached to a customer at
 * Stripe. We create a local PaymentMethod with a single gateway reference for
 * the source gateway.
 *
 * @implements WebhookEventHandler<Event>
 */
final readonly class PaymentMethodAttachedHandler implements WebhookEventHandler
{
    public function __construct(
        private GatewayPaymentMethodRecorder $recorder,
    ) {}

    #[Override]
    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var Event $event */
        $paymentMethod = $event->data->object;

        $paymentMethodReference = (string) ($paymentMethod->id ?? '');
        $customerReference = (string) ($paymentMethod->customer ?? '');
        if ($paymentMethodReference === '' || $customerReference === '') {
            return HandlerOutcome::Skipped;
        }

        if (($paymentMethod->type ?? '') !== 'card') {
            return HandlerOutcome::Skipped;
        }

        $card = $paymentMethod->card ?? null;
        if ($card === null) {
            return HandlerOutcome::Skipped;
        }

        $billingDetails = $paymentMethod->billing_details ?? null;
        $billingAddress = $this->extractBillingAddress($billingDetails);
        $identity = $this->extractIdentity($billingDetails);

        $expMonth = (int) ($card->exp_month ?? 0);
        $expYear = (int) ($card->exp_year ?? 0);
        if ($expMonth === 0 || $expYear === 0) {
            return HandlerOutcome::Skipped;
        }

        $first6 = (string) ($card->iin ?? str_pad('', 6, '0'));
        $brand = self::mapStripeBrand($card->brand ?? null);
        if ($brand === null) {
            return HandlerOutcome::Skipped;
        }

        $creditCard = new CreditCard(
            number: new Number(
                first6: $first6,
                last4: (string) ($card->last4 ?? ''),
                brand: $brand,
            ),
            expiration: Expiration::fromMonthAndYear($expMonth, $expYear),
            holder: new Holder((string) ($paymentMethod->billing_details->name ?? ShreddingStubs::NAME)),
            cvc: new Cvc,
        );

        return match ($this->recorder->onPaymentMethodRecord(
            gatewayId: $gatewayId,
            customerReference: $customerReference,
            paymentMethodReference: $paymentMethodReference,
            creditCard: $creditCard,
            billingAddress: $billingAddress,
            identity: $identity,
        )) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }

    /**
     * Stripe's `billing_details` is whatever the cardholder typed on the
     * gateway-hosted form — frequently incomplete or absent. We never skip the
     * webhook for that: missing required fields are filled with the matching
     * {@see ShreddingStubs} sentinel so the row is recorded with a stable,
     * recognisable "no data" marker (same shape as GDPR-erased rows).
     */
    private function extractBillingAddress(?object $details): BillingAddress
    {
        $address = $details?->address ?? null;

        $line = (string) ($address->line1 ?? '');
        $city = (string) ($address->city ?? '');
        $country = (string) ($address->country ?? '');
        $postalCode = (string) ($address->postal_code ?? '');
        $state = (string) ($address->state ?? '');

        return new BillingAddress(
            line: $line !== '' ? $line : ShreddingStubs::ADDRESS_LINE,
            city: $city !== '' ? $city : ShreddingStubs::CITY,
            country: new Country($country !== '' ? $country : ShreddingStubs::COUNTRY),
            postalCode: $postalCode !== '' ? $postalCode : ShreddingStubs::POSTAL_CODE,
            lineExtra: (string) ($address->line2 ?? ''),
            state: $state !== '' ? new State($state) : null,
        );
    }

    /**
     * The person `billing_details` names, which is the other half of what
     * {@see extractBillingAddress()} used to return on its own.
     *
     * Stripe sends one `name` and we hold two fields, so it is split — see {@see splitName()}
     * for what that costs.
     */
    private function extractIdentity(?object $details): CustomerIdentity
    {
        $email = (string) ($details->email ?? '');
        [$firstName, $lastName] = self::splitName(trim((string) ($details->name ?? '')));

        return new CustomerIdentity(
            firstName: $firstName,
            lastName: $lastName,
            email: $email !== '' ? new Email($email) : null,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitName(string $fullName): array
    {
        if ($fullName === '') {
            return [ShreddingStubs::NAME, ShreddingStubs::NAME];
        }

        $parts = preg_split('/\s+/', $fullName, 2) ?: [$fullName];

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Stripe documents `card.brand` as one of: `amex`, `cartes_bancaires`,
     * `diners`, `discover`, `eftpos_au`, `jcb`, `link`, `mastercard`,
     * `unionpay`, `visa`, `unknown`.
     *
     * @see https://docs.stripe.com/api/payment_methods/object#payment_method_object-card-brand
     *
     * Six labels map 1:1 to the domain enum via tryFrom (`amex`, `discover`,
     * `jcb`, `mastercard`, `unionpay`, `visa`). `diners` is shortened. The
     * remaining values (`cartes_bancaires`, `eftpos_au`, `link`, `unknown`)
     * have no domain counterpart, so the handler skips the record.
     */
    private static function mapStripeBrand(?string $value): ?CardBrand
    {
        return match ($value) {
            null, '', 'unknown', 'cartes_bancaires', 'eftpos_au', 'link' => null,
            'diners' => CardBrand::DinersClub,
            default => CardBrand::tryFrom($value),
        };
    }
}
