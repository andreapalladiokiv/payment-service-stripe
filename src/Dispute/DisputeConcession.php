<?php

declare(strict_types=1);

namespace Techork\PaymentService\Stripe\Dispute;

use RuntimeException;
use Stripe\Dispute;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Techork\PaymentService\Gateway\Command\DisputeConcessionCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Stripe\Concern\StripeRequestParameters;
use Techork\PaymentService\Stripe\StripeSettings;

/**
 * `POST /v1/disputes/:id/close` — concede the case, irreversibly.
 *
 * ## The one call in this domain that cannot be taken back
 *
 * Stripe moves `needs_response` to `lost`, the disputed money is gone, and there is no endpoint
 * that reopens a dispute. F7 is explicit that requiring an operator's confirmation before this is
 * dispatched belongs to the application (A2) and not here: this class holds no policy and no
 * screen, and prompting is not something a driver can do. What it owes is on the other side — that
 * a call arriving here does not concede more than it was asked to, and that a call that did not
 * close the case is never reported as one that did.
 *
 * ## Partial amounts are refused, not honoured approximately
 *
 * Stripe's `close` takes the whole case and has no amount parameter. An implementation that
 * ignored the amount and closed anyway would concede the entire disputed sum in place of the part
 * somebody decided to give up — on an irreversible call, with the difference being the whole
 * dispute. The refusal carries {@see UnsupportedOperation}, which is marked, so it propagates as a
 * wiring error instead of being recorded as a case the provider declined to close: no provider
 * decision was involved, and the recorded fact would otherwise be a concession nobody made.
 *
 * ## The answer is read, and only `lost` counts
 *
 * The plan states the move this call makes — `needs_response → lost` — and the response is checked
 * against it rather than assumed. `lost` is the only status that means our concession was applied:
 * `won` is the network deciding for us, `under_review` means it took the case anyway, and
 * `warning_closed` is a **different fact entirely** — the plan reads it as an inquiry that sat 120
 * days without escalating, so treating it as our concession would record a case we gave up on one
 * that expired on its own. Anything that is not `lost` is therefore refused loudly rather than
 * reported as a success: the layer above records an `ACCEPTED` case from this result and would
 * otherwise record a concession that this call did not make.
 *
 * The one outcome that refusal cannot express — the case was already closed before our call — is not
 * invented here: Stripe answers such a call with an error rather than with a status, so it arrives
 * as a failed result, and the port's own `AcceptOutcome::alreadyClosed()` is not reachable from this
 * provider without guessing at an error code Stripe does not document.
 */
final readonly class DisputeConcession
{
    use StripeRequestParameters;

    /**
     * What a dispute reports once our concession has been applied. One status, because the plan
     * states one move for this call and reads every other status as a different fact — see the
     * class docblock, where `warning_closed` in particular is an expiry rather than a concession.
     *
     * @var list<string>
     */
    private const array CONCEDED = [Dispute::STATUS_LOST];

    public function __construct(
        private StripeSettings $settings,
        private DisputeConcessionCommand $command,
    ) {}

    /**
     * The reference of the case Stripe closed, or a failed result carrying Stripe's own message.
     *
     * @throws UnsupportedOperation when a partial amount was asked for
     * @throws RuntimeException when Stripe answered, and the case is not closed
     */
    public function concede(): GatewayResult
    {
        $this->command->partialAmount === null || throw UnsupportedOperation::forGateway(
            'stripe',
            'concedePartially',
            'Stripe concedes the whole dispute or none of it: `close` has no partial amount, and '
            .'closing the case in place of the part that was asked for would give up the rest of '
            .'the disputed sum on a call that cannot be undone.',
        );

        try {
            $dispute = (new StripeClient($this->settings->apiKey))->disputes->close(
                $this->command->disputeReference,
                [],
                $this->stripeOpts($this->command->clientUniqueId, 'dispute-close'),
            );

            $status = $dispute->status ?? null;

            in_array($status, self::CONCEDED, true) || throw new RuntimeException(sprintf(
                'Stripe answered the close of dispute "%s" with status "%s", which is not a closed '
                .'case. Nothing is recorded from this call: reporting it as a concession would '
                .'book a case we gave up on a call that did not decide anything.',
                $this->command->disputeReference,
                $status ?? 'none stated',
            ));

            return GatewayResult::succeeded($dispute->id);
        } catch (ApiErrorException $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }
}
