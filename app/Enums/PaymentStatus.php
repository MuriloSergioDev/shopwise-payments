<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a payment: `processing` until the PaymentIntent is final, then exactly one outcome.
 * `requires_action` (3-D Secure) sits between them while the customer authenticates. `refunded` only follows
 * `succeeded` (a saga refund, or one made outside the saga, possibly partial).
 */
enum PaymentStatus: string
{
    case Processing = 'processing';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /**
     * Not final yet: the webhook, the deadline or a refund command decides.
     */
    public function isPending(): bool
    {
        return $this === self::Processing || $this === self::RequiresAction;
    }
}
