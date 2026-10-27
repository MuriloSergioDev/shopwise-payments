<?php

declare(strict_types=1);

return [
    // `stripe` or `fake` (App\Gateways\FakePaymentGateway). Defaults to Stripe when STRIPE_SECRET is set, so the
    // saga runs without keys; force `fake` to demo failures without touching Stripe.
    'gateway' => env('PAYMENTS_GATEWAY', env('STRIPE_SECRET') ? 'stripe' : 'fake'),

    // ADR-0004: a payment that is not final after this long is canceled and fails with `timeout`.
    'deadline_seconds' => (int) env('PAYMENTS_DEADLINE_SECONDS', 300),

    // Currency used only in the payment-failed reply for a checkout that never had a payment.
    'currency' => env('PAYMENTS_CURRENCY', 'USD'),
];
