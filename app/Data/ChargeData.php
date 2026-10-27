<?php

declare(strict_types=1);

namespace App\Data;

/**
 * What the gateway needs to create and confirm one PaymentIntent.
 */
final readonly class ChargeData
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public string $paymentMethodId,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {}
}
