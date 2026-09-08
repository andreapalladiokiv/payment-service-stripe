# Stripe gateway

`techork/payment-service-stripe` — Stripe implementation of the
`Techork\PaymentService\Gateway\Contract\Gateway` port, built on
[`stripe/stripe-php`](https://github.com/stripe/stripe-php). Each provider
operation is one class named for the operation, with a pure `payload()` that
builds the request body and one method that makes the call and returns a typed
result — there is no request/response lifecycle. Charges run through the
[PaymentIntents API](https://docs.stripe.com/api/payment_intents); every
amount is passed as `Money` minor units, verbatim.

The `composer.json` `extra.laravel` block wires the package into the Laravel
bridge: `gateway` → `StripeGateway`, `webhook` → `Webhook\StripeWebhookSubscriber`.

## Configuration

| Key | Used by | Meaning |
| --- | --- | --- |
| `apiKey` | `StripeGateway::configure()`, then `StripeSettings` on every operation | Stripe secret key for all SDK calls |
| `api_key` | `ChargeUpdatedHandler`, `ChargeRefundUpdatedHandler` (via `GatewayCredential::getCredentials()`) | Same secret key, read from the stored credential when a webhook must refetch a BalanceTransaction |
| `webhook_signing_key` | `Webhook\SignatureVerifier` | Stripe webhook signing secret (`whsec_…`) |

The command's `clientUniqueId` is forwarded as the Stripe
[`idempotency_key`](https://docs.stripe.com/api/idempotent_requests) (see
`StripeRequestParameters::stripeOpts()`); no key is sent when it is unset.
`createCustomer` / `updateCustomer` pass no opts and never send one.

## Operations

Each row is one class. The gateway's role method performs it; the accessor
beside it builds the operation without performing it, so a test can read
`payload()` without an API key.

| Role | Accessor | Operation class | Stripe call | Notes |
| --- | --- | --- | --- | --- |
| `charge` | `charging()` | `Charge` | `paymentIntents.create` (`confirm: true`) | `HostedPayment` instrument switches to a Checkout Session instead |
| `authorize` | `authorizing()` | `Authorize` | `paymentIntents.create` (`capture_method: manual`) | |
| `authorizeRebilling` | `authorizingRebilling()` | `Authorize` | as above | the series position reaches Stripe as `off_session` plus the attached customer, so `RebillingCommand::toPlacement()` drops the genesis reference Stripe has no field for |
| `capture` | `capturing()` | `Capture` | `paymentIntents.capture` | the command's amount → `amount_to_capture` |
| `refund` | `refunding()` | `Refund` | `refunds.create` | by `payment_intent` reference |
| `cancel` | `cancelling()` | `Cancel` | `paymentIntents.cancel` | succeeds only when the answer's status is `canceled` — Stripe returns 200 for a cancel it did not perform |
| `tokenize` | `tokenizing()` | `Tokenize` | `tokens.create` | raw card → single-use `tok_…` |
| `registerPaymentMethod` | `registering()` | `RegisterPaymentMethod` | `paymentMethods.create` (+ `attach`, + confirmed SetupIntent) | reusable `pm_…`; the SetupIntent runs AVS/CVC checks and saves the PM for off-session reuse; the PM is re-retrieved to read the checks. Refused outright when no customer resolved: an unattached PM is single-use |
| — | `createCustomer()` / `updateCustomer()` | `CreateCustomer` / `UpdateCustomer` | `customers.create` / `customers.update` | not a role; `cus_…` as the reference. Called by `resolveCustomerReference()` below |
| `retryRefund` | — | — | — | throws `RuntimeException`, deliberately unmarked so a refund can still fail gracefully (Stripe can only refund to the original source) |
| `issueVirtualCard`, `updateVirtualCard`, `terminateVirtualCard` | — | — | — | throw `UnsupportedOperation` (Stripe Issuing is a separate product; reaching these is a misroute) |

### Instruments

`Charge`, `Authorize`, `Tokenize` and `RegisterPaymentMethod` implement
`PaymentInstrumentVisitor`:

- `CreditCard` — PAN/CVC decrypted via the configured `decrypter` and sent as `payment_method_data` (`Tokenize` sends a bare `card` payload to `tokens.create`).
- `Token` — resolved to a `tok_…` through the `referenceResolver` (`GatewayInstrumentRepository`); missing reference throws.
- `PaymentMethod` — resolved to a `pm_…`. `off_session` is set from the command's `initiation`, NOT from the instrument being stored: a saved card in a live checkout is cardholder-initiated.
- `HostedPayment` — `charge` only: creates a `mode: payment` Checkout Session and returns a `RedirectChallenge`; the underlying PaymentIntent id is used as the gateway reference so the `payment_intent.succeeded` webhook resolves it.
- `Cash` — unsupported, throws.

### Customer resolution

`StripeGateway` resolves a `customerReference` before building any placement or
vaulting operation and hands it to the constructor (`resolveCustomerReference()`):
look up the instrument's customer in the injected `CustomerRepository`
(empty string counts as missing — legacy rows), otherwise adopt the owning
customer straight from Stripe when the `pm_…` is already attached there
(`adoptCustomerFromStripe()` repairs the local link), otherwise create a new
Stripe customer from the `billingAddress`. An email is NOT required for that
last step: a PaymentMethod with no Customer is single-use, so gating on an
optional field registered cards nobody could charge.

### 3-D Secure

The command's `threeDS` is forwarded as
`payment_method_options.card.three_d_secure` (cryptogram, DS transaction id,
version, `ares_trans_status`, ECI) on `charge` and `authorize`. Absent members
are omitted rather than sent as null, and an attestation with no cryptogram is
refused with `IncompleteAuthentication` rather than shipped — see
`Concern\FormatsThreeDS`. A `VaultCommand` carries no attestation, so the
SetupIntent sends no such block.

When Stripe answers `requires_action`, `StripeChallenge` reads which of its two
shapes came back: `redirect_to_url` becomes a `ThreeDSChallenge`, and
`use_stripe_sdk` an `SdkChallenge` — or a `ThreeDSChallenge` on the configured
`authenticationUrl` when the deployment would rather have an address.
PaymentIntents are created with `allow_redirects: 'never'` unless a `returnUrl`
is configured, in which case Stripe may answer with an address of its own.

### Results

Operations return the Gateway's own result types directly — `GatewayResult` for
capture / refund / cancel, `AuthorizationResult` for charge / authorize,
`RegistrationResult` for tokenize / registerPaymentMethod. There is no response
class and no shared assembler in between.

`PaymentIntentOutcome` is the one mapping from a `PaymentIntent` to an
`AuthorizationResult`, and it decides success from the STATUS the operation
named — `requires_capture` for an authorize, `succeeded` for a charge. An id is
present in every state, `requires_action` included, so reading success off the
id reported a card still owing 3DS as an authorization. It also attaches:

- AVS line / postal-code / CVC results, extracted from the expanded `payment_method.card.checks` and normalized to the `CheckResult` enum (`ExtractsCardChecks`); unknown Stripe values become `null`.
- the FX-settled amount from the expanded `latest_charge.balance_transaction`, only when the charge's currency differs from the balance transaction's settlement currency — Stripe's `exchange_rate` is deliberately not consulted (`ExtractsConvertedAmount`); populated on charge and capture.
- `opening_transaction_reference` metadata — which transaction OPENED the intent, because `reference` is overwritten on transition and cannot answer that once a capture lands. Only the opening operations map through here, so a settle cannot bury the anchor.

`ApiErrorException` is never thrown to the caller: operations catch it and
return a failed result whose `message` is the Stripe error message. Structural
refusals (`UnsupportedInstrument`, `UnsupportedOperation`,
`IncompleteAuthentication`) are NOT caught — they carry the
`UnsupportedByGateway` marker so the router rethrows rather than recording an
acquirer decline for a request no acquirer saw.

## Webhooks

`StripeWebhookSubscriber` registers the `Stripe` kind with the Gateway webhook
registries. `SignatureVerifier` validates the `Stripe-Signature` header with
the SDK's `WebhookSignature::verifyHeader()` (default tolerance);
`EventParser` rebuilds the `Stripe\Event` and uses the event id (`evt_…`) as
the idempotency key. Handlers return `Processed` / `Skipped` / `Delay`
(delay = local transaction not resolvable yet, retry later):

| Event | Handler | Effect |
| --- | --- | --- |
| `payment_intent.succeeded` | `PaymentIntentSucceededHandler` | records gateway success with `amount_received` |
| `payment_intent.canceled` | `PaymentIntentCanceledHandler` | records gateway cancellation |
| `payment_intent.payment_failed` | `PaymentIntentFailedHandler` | records failure with `last_payment_error` message/code |
| `charge.refunded` | `ChargeRefundedHandler` | records the latest refund (covers dashboard-issued refunds) |
| `charge.updated` | `ChargeUpdatedHandler` | refetches the BalanceTransaction by id to record the processor fee (payload carries only the BT id) |
| `charge.refund.updated` | `ChargeRefundUpdatedHandler` | same, for the refund's fee |
| `payment_method.attached` | `PaymentMethodAttachedHandler` | creates a local `PaymentMethod` from the card data; unmappable `card.brand` values (`link`, `cartes_bancaires`, `eftpos_au`, `unknown`) are skipped; missing billing details are filled with `ShreddingStubs` sentinels |
| `payment_method.detached` | `PaymentMethodDetachedHandler` | forgets the gateway-side `pm_…` reference; local data is kept |

## Testing

Pest unit tests only — the `StripeClient` is never hit, so no credentials are
needed; where an operation is exercised end-to-end, a stub is installed through
`ApiRequestor::setHttpClient()` and restored in `afterEach`.
`ThreeDSIntegrationTest` and `StripeOffSessionTest` assert on what the SDK
actually puts on the wire, which is the only way to tell a parameter that was
built from one that was dropped on the way.
