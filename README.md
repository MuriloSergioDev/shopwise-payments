# shopwise-payments

Shopwise payments service: one Stripe (test mode) PaymentIntent per checkout, refunds as the saga's compensation,
and the Stripe webhook. Consumes `payments.request-payment` / `payments.refund-payment` from checkout and replies
`payments.payment-succeeded`, `payments.payment-failed` or `payments.refunded` through the outbox.

Run it from `shopwise-platform`: `tilt up -- --only=identity,payments` (port 8107). Without Stripe keys it uses a fake
gateway; to use Stripe, create the `payments-secrets` Secret and run `stripe listen` as described in `AGENTS.md`.

Conventions, env vars and the failure test cards: `AGENTS.md`.
