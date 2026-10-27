# shopwise-payments

One Stripe PaymentIntent per checkout, refunds, and the Stripe webhook. Step 4 of checkout's saga and its
compensation (`shopwise-platform/docs/adr/0004-checkout-saga.md`). Created from `shopwise-service-template`:
structure, checks and how to run PHP are described there and still apply.

## Service facts
- **Owns**: `payments` (ULID id, unique `checkout_id`, `customer_id`, `amount` minor units + `currency`, `payment_method_id`, `status` processing|requires_action|succeeded|failed|refunded, `stripe_payment_intent_id`, `client_secret` + `action_expires_at` (3-D Secure), failure reason/message, refund id/reason/`refunded_amount`, `*_at` timestamps) and `stripe_events` (Stripe event ids already handled). Database `payments`.
- **Consumes** (consumer `payment-commands`): commands from checkout `payments.request-payment` and `payments.refund-payment` (`schemas/commands/payments/`). Payments owns these topics (ADR-0004: the receiver owns command topics).
- **Publishes** (key = paymentId; key = checkoutId only for the "never had a payment" failure): `payments.payment-succeeded`, `payments.payment-failed`, `payments.refunded`, and the interim `payments.payment-requires-action` (3-D Secure).
- **HTTP** — contract `shopwise-contracts/openapi/payments.yaml`:
  - `POST /api/webhooks/stripe` — called by Stripe, authenticated by the `Stripe-Signature` header only.
  - `GET /api/checkouts/{checkoutId}/payment` (`auth.jwt`, the caller's own; others are 404).
  - `GET /api/admin/payments?status&page&perPage` (`auth.jwt:admin`).
  - Dev only, not in the contract: `POST /api/dev/payment-actions` (see "Finishing a fake 3-D Secure payment").
- **Scheduler**: `payments:expire-stale` every minute (plus the kit's outbox relay).
- **REST dependencies**: identity's JWKS (`IDENTITY_JWKS_URL`); Stripe's API when `PAYMENTS_GATEWAY=stripe`.
- **Local URL**: http://localhost:8107 (Tilt port-forward).

## Rules
- Stripe is behind `App\Gateways\PaymentGateway`. Only `App\Gateways` and the provider touch the Stripe SDK (arch test).
  `StripePaymentGateway` in the cluster, `FakePaymentGateway` in tests and whenever no `STRIPE_SECRET` is set.
- One payment per `checkoutId`. A repeated `request-payment` never charges again: it re-publishes the current outcome
  (a new envelope id, same data; `payment-requires-action` while 3-D Secure is pending), or asks Stripe again while
  the payment is processing.
- Stripe idempotency keys: `checkout-{checkoutId}-pay` (create + confirm), `checkout-{checkoutId}-refund`.
- The PaymentIntent is confirmed synchronously (`confirm: true`, `allow_redirects: never`). A decline is a result, not
  an exception.
- 3-D Secure: `requires_action` is kept. The payment goes to `requires_action` and `payment-requires-action` is
  published with `clientSecret` and `expiresAt` (intent created + `PAYMENTS_ACTION_DEADLINE_SECONDS`, 600). The
  storefront runs `stripe.handleNextAction`; the webhook (`payment_intent.succeeded` / `payment_failed`, the latter
  `authentication_required`) finishes it. The client secret is never in the HTTP API and is cleared once final.
- Lost Stripe answer: when creating/confirming fails without saying whether Stripe charged (no response, timeout,
  5xx → `ChargeOutcomeUnknown`), the payment row is kept, processing, with no intent id. A re-sent `request-payment`,
  `refund-payment`, the deadline and the webhook (by `metadata.checkoutId`) find the intent with
  `PaymentGateway::find()` (Stripe search on the metadata), so money Stripe took is settled and can be refunded.
  Any other gateway error (4xx, bad key) throws, rolls back the payment row and lets the consumer retry (then DLQ).
- `App\Actions\SettlePayment` is the only place a pending payment gets its outcome: the synchronous confirm, the
  webhook, the deadline and the fake action all go through it, and only the first one publishes.
- Deadline (`payments:expire-stale`): a payment still processing after `PAYMENTS_DEADLINE_SECONDS` (300), or still
  `requires_action` at `action_expires_at`, is checked with Stripe, then canceled and failed with `timeout`.
- `refund-payment`: when the command's optional `customerId`/`amount`/`currency` disagree with the payment → error log,
  no refund, no reply. Succeeded → refund → `refunded`; processing/requires_action → cancel → `payment-failed`
  (`canceled`) (or refund if it had succeeded); refunded only externally → refund what is left and reply `refunded`
  with the saga reason; failed/refunded → re-publish; no payment at all → `payment-failed` with `paymentId: null`
  and the command's customerId/amount/currency (`""`/`0`/`PAYMENTS_CURRENCY` for an older command without them).
- Webhook: signature checked against the raw body first (400 otherwise, also when no secret is set); deduped on the
  Stripe event id. Handles `payment_intent.succeeded|payment_failed|canceled` and `charge.refunded`; others are 200
  and ignored. `charge.refunded` not caused by the saga (dashboard/API) → status refunded, `refunded` with reason
  `external` and the amount this refund added (partial refunds included; `refunded_amount` keeps the total). The
  saga's own refund is recognised by its reason (the refund command holds the row lock until it is recorded).
- Event data always comes from the generated contract classes (`Payment::succeededEvent()`, `failedEvent()`,
  `refundedEvent()`, `requiresActionEvent()`; the external refund builds its `RefundedV1` in `HandleStripeEvent`); nullable schema fields are nullable properties there.

## Stripe (test mode only)
Keys come only from env. Never commit them; `.env.example` has empty placeholders.

| Env var | What |
|---|---|
| `STRIPE_SECRET` | `sk_test_...` secret key. Unset → fake gateway. |
| `STRIPE_WEBHOOK_SECRET` | `whsec_...` signing secret printed by `stripe listen`. Unset → every webhook is a 400. |
| `PAYMENTS_GATEWAY` | `stripe` or `fake`; defaults to `stripe` when `STRIPE_SECRET` is set. |
| `PAYMENTS_DEADLINE_SECONDS` | default 300. |
| `PAYMENTS_ACTION_DEADLINE_SECONDS` | 3-D Secure `expiresAt` after the intent was created; default 600. |

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
| `pm_card_threeDSecure2Required`, `pm_card_authenticationRequired` | `payment-requires-action`, then the webhook (or the fake action below) decides; `timeout` after 10 minutes |
| `pm_fake_processing` (fake only) | stays processing until the deadline → `timeout` |
| `pm_fake_lostResponse` (fake only) | the charge "loses" Stripe's answer: stays processing with no intent until a re-send, refund or the deadline finds it (succeeded) |

### Finishing a fake 3-D Secure payment
With the fake gateway (no `STRIPE_SECRET`, or `PAYMENTS_GATEWAY=fake`) the client secret is `<intentId>_secret_fake` and
Stripe.js cannot use it. Finish the action instead with the client secret (what checkout shows its customer):

```sh
curl -X POST localhost:8107/api/dev/payment-actions -H 'Content-Type: application/json' \
  -d '{"clientSecret":"pi_fake_action_..._secret_fake","outcome":"succeeded"}'   # or "failed"
```

200 with the Payment resource; 404 with the Stripe gateway or an unknown secret; 422 for a bad body. Or by checkout id:
`kubectl exec deploy/payments-http -- php artisan payments:fake-complete-action <checkoutId> [--fail]`. Either one
settles it like the webhook would: `payment-succeeded`, or `payment-failed` with `authentication_required`.

## Data
Nothing to seed: payments exist only once checkout asks for one.
