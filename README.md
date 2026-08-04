# Stripe gateway

`techork/payment-service-stripe` — Stripe implementation of the
`Techork\PaymentService\Gateway\Contract\Gateway` port, built on
[`stripe/stripe-php`](https://github.com/stripe/stripe-php) and Omnipay's
request/response plumbing. Charges run through the
[PaymentIntents API](https://docs.stripe.com/api/payment_intents); every
amount is passed as `Money` minor units, verbatim.

The `composer.json` `extra.laravel` block wires the package into the Laravel
bridge: `gateway` → `StripeGateway`, `webhook` → `Webhook\StripeWebhookSubscriber`.

## Configuration

| Key | Used by | Meaning |
| --- | --- | --- |
| `apiKey` | `StripeGateway::initialize()` / every request | Stripe secret key for all SDK calls |
| `api_key` | `ChargeUpdatedHandler`, `ChargeRefundUpdatedHandler` (via `GatewayCredential::getCredentials()`) | Same secret key, read from the stored credential when a webhook must refetch a BalanceTransaction |
| `webhook_signing_key` | `Webhook\SignatureVerifier` | Stripe webhook signing secret (`whsec_…`) |

`setClientUniqueId()` is forwarded as the Stripe
[`idempotency_key`](https://docs.stripe.com/api/idempotent_requests) (see
`StripeRequestParameters::stripeOpts()`); no key is sent when it is unset.
`createCustomer` / `updateCustomer` pass no opts and never send one.

## Operations

| Operation | Stripe call | Notes |
| --- | --- | --- |
| `purchase` | `paymentIntents.create` (`confirm: true`) | `HostedPayment` instrument switches to a Checkout Session instead |
| `authorize` | `paymentIntents.create` (`capture_method: manual`) | |
| `capture` | `paymentIntents.capture` | optional `money` → partial `amount_to_capture` |
| `refund` | `refunds.create` | by `payment_intent` reference |
| `void` | `paymentIntents.cancel` | `VoidResponse` succeeds only when status is `canceled` |
| `createCard` | `tokens.create` | raw card → single-use `tok_…` |
| `createPaymentMethod` | `paymentMethods.create` (+ `attach`, + confirmed SetupIntent) | reusable `pm_…`; the SetupIntent runs AVS/CVC checks and saves the PM for off-session reuse; the PM is re-retrieved to read the checks |
| `createCustomer` / `updateCustomer` | `customers.create` / `customers.update` | `cus_…` as transaction reference |
| `retryRefund`, `issueVirtualCard`, `updateVirtualCard`, `terminateVirtualCard` | — | throw `RuntimeException` (Stripe can only refund to the original source; no card issuing here) |

### Instruments

`PurchaseRequest`, `AuthorizeRequest`, `CreateCardRequest` and
`CreatePaymentMethodRequest` implement `PaymentInstrumentVisitor`:

- `CreditCard` — PAN/CVC decrypted via the configured `decrypter` and sent as `payment_method_data` (`createCard` sends a bare `card` payload to `tokens.create`).
- `Token` — resolved to a `tok_…` through the `referenceResolver` (`GatewayInstrumentRepository`); missing reference throws.
- `PaymentMethod` — resolved to a `pm_…`; charged with `off_session: true` (MIT).
- `HostedPayment` — `purchase` only: creates a `mode: payment` Checkout Session and returns a `RedirectChallenge`; the underlying PaymentIntent id is used as the gateway reference so the `payment_intent.succeeded` webhook resolves it.
- `Cash` — unsupported, throws.

### Customer resolution

`StripeGateway::purchase()/authorize()/createPaymentMethod()` resolve a
`customerReference` before building the request (`resolveCustomerReference()`):
look up the instrument's customer in the injected `CustomerRepository`
(empty string counts as missing — legacy rows), otherwise adopt the owning
customer straight from Stripe when the `pm_…` is already attached there
(`adoptCustomerFromStripe()` repairs the local link), otherwise create a new
Stripe customer — but only when a `billingAddress` with an email is available.

### 3-D Secure

A `ThreeDSResult` passed via `setThreeDS()` is forwarded as
`payment_method_options.card.three_d_secure` (cryptogram, DS transaction id,
version, `ares_trans_status`, ECI) on purchase, authorize and the
`createPaymentMethod` SetupIntent. When Stripe answers `requires_action`, the
response carries a `ThreeDSChallenge` (PI id, redirect URL, `client_secret`).
PaymentIntents are created with
`automatic_payment_methods: {enabled: true, allow_redirects: 'never'}`.

### Responses

All responses extend `StripeResponse` (success ⇔ a `reference` is present),
which implements the Gateway provider contracts:

- `CardChecksProvider` — AVS line / postal-code / CVC results, extracted from the expanded `payment_method.card.checks` and normalized to the `CheckResult` enum (`ExtractsCardChecks`); unknown Stripe values become `null`.
- `ConvertedAmountProvider` — FX-settled amount from the expanded `latest_charge.balance_transaction`, only when the charge's currency differs from the balance transaction's settlement currency — Stripe's `exchange_rate` is deliberately not consulted (`ExtractsConvertedAmount`); populated on purchase and capture.
- `ChallengeProvider` — `ThreeDSChallenge` / `RedirectChallenge`, see above.

`ApiErrorException` is never thrown to the caller: requests catch it and
return a failed response whose `getMessage()` is the Stripe error message.

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
needed. `ThreeDSIntegrationTest` and `StripeRequestParametersTest` show
realistic request wiring (3DS pass-through, idempotency opts).
