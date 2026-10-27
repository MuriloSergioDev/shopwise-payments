<?php

declare(strict_types=1);

namespace App\Gateways;

use App\Data\ChargeData;
use App\Data\IntentResult;

/**
 * The payment provider. Stripe in the cluster (StripePaymentGateway), FakePaymentGateway in tests and when no
 * Stripe key is configured. A declined card is a result, not an exception; exceptions mean "try again later".
 */
interface PaymentGateway
{
    /**
     * Creates and confirms a PaymentIntent (no redirects). The same idempotency key returns the same intent.
     */
    public function charge(ChargeData $charge): IntentResult;

    public function retrieve(string $intentId): IntentResult;

    /**
     * Cancels a PaymentIntent that is not final. One that already succeeded is returned as it is.
     */
    public function cancel(string $intentId): IntentResult;

    /**
     * Refunds a succeeded PaymentIntent in full and returns the refund id.
     */
    public function refund(string $intentId, string $idempotencyKey): string;
}
