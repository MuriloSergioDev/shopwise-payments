<?php

declare(strict_types=1);

return [
    // `stripe` or `fake` (App\Gateways\FakePaymentGateway). Defaults to Stripe when STRIPE_SECRET is set, so the
    // saga runs without keys; force `fake` to demo failures without touching Stripe.
    'gateway' => env('PAYMENTS_GATEWAY', env('STRIPE_SECRET') ? 'stripe' : 'fake'),

    // ADR-0004: a payment that is not final after this long is canceled and fails with `timeout`.
    'deadline_seconds' => (int) env('PAYMENTS_DEADLINE_SECONDS', 300),

    // ADR-0004: a PaymentIntent waiting for 3-D Secure is canceled this long after it was created (`expiresAt` of
    // payments.payment-requires-action).
    'action_deadline_seconds' => (int) env('PAYMENTS_ACTION_DEADLINE_SECONDS', 600),

    // Currency used only in the payment-failed reply for a checkout that never had a payment, when the refund
    // command does not carry one.
    'currency' => env('PAYMENTS_CURRENCY', 'USD'),
];
