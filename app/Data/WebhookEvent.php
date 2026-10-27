<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A verified Stripe event. `intent` is set for payment_intent.* events, `intentId` for those and charge.refunded.
 * `checkoutId` comes from the intent's metadata (finds a payment whose charge response was lost). For
 * charge.refunded, `amountRefunded` is the charge's running total and `refundId` its newest refund, when the
 * payload lists it.
 */
final readonly class WebhookEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public ?string $intentId = null,
        public ?IntentResult $intent = null,
        public ?string $checkoutId = null,
        public ?int $amountRefunded = null,
        public ?string $refundId = null,
    ) {}
}
