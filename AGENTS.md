# shopwise-payments

One Stripe PaymentIntent per checkout, refunds, and the Stripe webhook. Step 4 of checkout's saga and its
compensation (`shopwise-platform/docs/adr/0004-checkout-saga.md`). Created from `shopwise-service-template`:
structure, checks and how to run PHP are described there and still apply.

## Service facts
- **Owns**: `payments` (ULID id, unique `checkout_id`, `customer_id`, `amount` minor units + `currency`, `payment_method_id`, `status` processing|succeeded|failed|refunded, `stripe_payment_intent_id`, failure reason/message, refund id/reason, `*_at` timestamps) and `stripe_events` (Stripe event ids already handled). Database `payments`.
- **Consumes** (consumer `payment-commands`): commands from checkout `payments.request-payment` and `payments.refund-payment` (`schemas/commands/payments/`). Payments owns these topics (ADR-0004: the receiver owns command topics).
- **Publishes** (key = paymentId; key = checkoutId only for the "never had a payment" failure): `payments.payment-succeeded`, `payments.payment-failed`, `payments.refunded`.
- **HTTP** — contract `shopwise-contracts/openapi/payments.yaml`:
  - `POST /api/webhooks/stripe` — called by Stripe, authenticated by the `Stripe-Signature` header only.
  - `GET /api/checkouts/{checkoutId}/payment` (`auth.jwt`, the caller's own; others are 404).
  - `GET /api/admin/payments?status&page&perPage` (`auth.jwt:admin`).
- **Scheduler**: `payments:expire-stale` every minute (plus the kit's outbox relay).
- **REST dependencies**: identity's JWKS (`IDENTITY_JWKS_URL`); Stripe's API when `PAYMENTS_GATEWAY=stripe`.
- **Local URL**: http://localhost:8107 (Tilt port-forward).

## Rules
- Stripe is behind `App\Gateways\PaymentGateway`. Only `App\Gateways` and the provider touch the Stripe SDK (arch test).
  `StripePaymentGateway` in the cluster, `FakePaymentGateway` in tests and whenever no `STRIPE_SECRET` is set.
- One payment per `checkoutId`. A repeated `request-payment` never charges again: it re-publishes the current outcome
  (a new envelope id, same data), or asks Stripe again while the payment is processing.
- Stripe idempotency keys: `checkout-{checkoutId}-pay` (create + confirm), `checkout-{checkoutId}-refund`.
- The PaymentIntent is confirmed synchronously (`confirm: true`, `allow_redirects: never`). `requires_action` (3-D Secure)
  is canceled and fails with `authentication_required`. A decline is a result, not an exception; any other gateway
  error throws, rolls back the payment row and lets the consumer retry (then DLQ).
- `App\Actions\SettlePayment` is the only place a processing payment gets its outcome: the synchronous confirm, the
  webhook and the deadline all go through it, and only the first one publishes.
- Deadline: a payment still processing after `PAYMENTS_DEADLINE_SECONDS` (300) is checked with Stripe, then canceled
  and failed with `timeout`, so checkout always gets a reply within 5 minutes.
- `refund-payment`: succeeded → refund → `refunded`; processing → cancel → `payment-failed` (`canceled`) (or refund if
  it had succeeded); failed/refunded → re-publish; no payment at all → `payment-failed` with `paymentId: null`,
  `customerId: ""`, `amount: 0`, currency `PAYMENTS_CURRENCY` (the command carries none of these).
- Webhook: signature checked against the raw body first (400 otherwise, also when no secret is set); deduped on the
  Stripe event id. Handles `payment_intent.succeeded|payment_failed|canceled` and `charge.refunded`; others are 200
  and ignored. `charge.refunded` for a payment still `succeeded` (a dashboard refund) marks it refunded without an
  event: no saga reason fits.
- Event data always comes from the generated contract classes (`Payment::succeededEvent()`, `failedEvent()`,
  `refundedEvent()`); nullable schema fields are nullable properties there.

## Stripe (test mode only)
Keys come only from env. Never commit them; `.env.example` has empty placeholders.

| Env var | What |
|---|---|
| `STRIPE_SECRET` | `sk_test_...` secret key. Unset → fake gateway. |
| `STRIPE_WEBHOOK_SECRET` | `whsec_...` signing secret printed by `stripe listen`. Unset → every webhook is a 400. |
| `PAYMENTS_GATEWAY` | `stripe` or `fake`; defaults to `stripe` when `STRIPE_SECRET` is set. |
| `PAYMENTS_DEADLINE_SECONDS` | default 300. |

No publishable key: the storefront sends a Stripe payment method id (`pm_...`); payments never renders Stripe.js.

In the cluster the chart loads the optional Secret `payments-secrets` with `envFrom` (do not put keys in `deploy/values.yaml`):

```sh
kubectl create secret generic payments-secrets --from-literal=STRIPE_SECRET=sk_test_... --from-literal=STRIPE_WEBHOOK_SECRET=whsec_...
kubectl rollout restart deploy -l app=payments   # http, consumer and scheduler pods pick up the Secret
```

Webhooks reach the local cluster through the Stripe CLI, forwarding to the Tilt port-forward:

```sh
stripe login
stripe listen --forward-to localhost:8107/api/webhooks/stripe \
  --events payment_intent.succeeded,payment_intent.payment_failed,payment_intent.canceled,charge.refunded
```

`stripe listen` prints the `whsec_...` for `STRIPE_WEBHOOK_SECRET`; it stays the same for your Stripe account and device.
`stripe trigger payment_intent.succeeded` sends a test event (it creates its own intent, so payments logs it as unknown
and answers 200).

### Forcing failures (saga compensation demo)
Use Stripe's test payment methods as `paymentMethodId`; the fake gateway gives the same outcomes:

| `paymentMethodId` | Result |
|---|---|
| `pm_card_visa` | succeeded |
| `pm_card_chargeDeclined` | `payment-failed`, `card_declined` |
| `pm_card_chargeDeclinedInsufficientFunds` | `payment-failed`, `insufficient_funds` |
| `pm_card_authenticationRequired` | canceled, `payment-failed`, `authentication_required` |
| `pm_fake_processing` (fake only) | stays processing until the deadline → `timeout` |

## Data
Nothing to seed: payments exist only once checkout asks for one.
