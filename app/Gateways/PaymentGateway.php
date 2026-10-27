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
     *
     * @throws ChargeOutcomeUnknown when Stripe may have created (and charged) the intent but no answer came back
     */
    public function charge(ChargeData $charge): IntentResult;

    /**
     * The PaymentIntent an earlier charge() created for this checkout (found by its metadata), if any. Reconciles a
     * charge whose outcome is unknown.
     */
    public function find(ChargeData $charge): ?IntentResult;

    public function retrieve(string $intentId): IntentResult;

    /**
     * Cancels a PaymentIntent that is not final. One that already succeeded is returned as it is.
     */
    public function cancel(string $intentId): IntentResult;

    /**
     * Refunds what is left of a succeeded PaymentIntent and returns the refund id.
     */
    public function refund(string $intentId, string $idempotencyKey): string;

    /**
     * The id of the newest refund of a PaymentIntent, for refunds made outside the saga.
     */
    public function latestRefundId(string $intentId): ?string;
}
