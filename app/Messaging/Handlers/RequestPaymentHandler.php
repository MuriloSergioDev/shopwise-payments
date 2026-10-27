<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Actions\RequestPayment;
use Shopwise\Contracts\Commands\Payments\RequestPaymentV1;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * Checkout's step 4 (ADR-0004): charge the checkout and reply payment-succeeded or payment-failed.
 */
final readonly class RequestPaymentHandler implements MessageHandler
{
    public function __construct(private RequestPayment $requestPayment) {}

    public function handle(Envelope $envelope): void
    {
        $this->requestPayment->handle(RequestPaymentV1::fromArray($envelope->data));
    }
}
