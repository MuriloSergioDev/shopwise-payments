<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a payment: `processing` until the PaymentIntent is final, then exactly one outcome.
 * `refunded` only follows `succeeded`.
 */
enum PaymentStatus: string
{
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
