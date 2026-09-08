<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe;

use Override;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;

/**
 * @implements PaymentInstrumentVisitor<array>
 */
final class Tokenize implements PaymentInstrumentVisitor
{
    use StripeRequestParameters;

    public function __construct(
        private readonly GatewayInfrastructure $infrastructure,
        private readonly StripeSettings $settings,
        private readonly VaultCommand $command,
        private readonly ?string $customerReference = null,
    ) {}

    /**
     * The shape is spelled out rather than left as `array<string, mixed>`, because Stripe's SDK
     * declares an exhaustive shape for `tokens.create` and a looser type reaches the call as a
     * coercion. Only {@see visitCreditCard()} returns at all — every other instrument throws —
     * so the one shape it builds is the whole return type, and the annotation on the accept()
     * result is what carries it past the visitor's untyped contract.
     *
     * @return array{card: array<string, string>}
     */
    public function payload(): array
    {
        /** @var PaymentInstrument $instrument */
        $instrument = $this->command->instrument;

        /** @var array{card: array<string, string>} */
        return $instrument->accept($this);
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->infrastructure->decrypter;

        $data = [
            'card' => [
                'number' => $card->number->getNumber($decrypter),
                'exp_month' => $card->expiration->format('m'),
                'exp_year' => $card->expiration->format('Y'),
            ],
        ];

        $cvv = $card->cvc->getCvc($decrypter);
        if ($cvv !== null && $cvv !== '') {
            $data['card']['cvc'] = $cvv;
        }

        $name = (string) $card->holder;
        if ($name !== '') {
            $data['card']['name'] = $name;
        }

        return $data;
    }

    #[Override]
    public function visitCash(Cash $cash): mixed
    {
        throw new RuntimeException('Stripe does not support cash payments.');
    }

    #[Override]
    public function visitToken(Token $token): never
    {
        throw new RuntimeException('Token does not support tokenization.');
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod does not support tokenization.');
    }

    public function tokenize(): RegistrationResult
    {
        try {
            $stripe = new StripeClient($this->settings->apiKey);

            $token = $stripe->tokens->create($this->payload(), $this->stripeOpts($this->command->clientUniqueId));

            return RegistrationResult::succeeded($token->id);
        } catch (ApiErrorException $e) {
            return RegistrationResult::failed($e->getMessage());
        }
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('stripe', 'createCard', $hosted);
    }
}
