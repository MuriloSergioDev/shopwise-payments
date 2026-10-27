<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A verified Stripe event. `intent` is set for payment_intent.* events, `intentId` for those and charge.refunded.
 */
final readonly class WebhookEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public ?string $intentId = null,
        public ?IntentResult $intent = null,
    ) {}
}
