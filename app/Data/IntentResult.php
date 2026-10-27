<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\FailureReason;
use App\Enums\IntentStatus;

/**
 * A PaymentIntent as the gateway reports it, reduced to what settling a payment needs.
 */
final readonly class IntentResult
{
    public function __construct(
        public string $intentId,
        public IntentStatus $status,
        public ?FailureReason $failureReason = null,
        public ?string $failureMessage = null,
    ) {}
}
