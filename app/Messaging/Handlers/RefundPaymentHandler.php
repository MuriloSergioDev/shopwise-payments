<?php

declare(strict_types=1);

namespace App\Messaging\Handlers;

use App\Actions\RefundPayment;
use Shopwise\Contracts\Commands\Payments\RefundPaymentV1;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageHandler;

/**
 * Checkout's compensation: undo the checkout's payment and reply refunded or payment-failed.
 */
final readonly class RefundPaymentHandler implements MessageHandler
{
    public function __construct(private RefundPayment $refundPayment) {}

    public function handle(Envelope $envelope): void
    {
        $this->refundPayment->handle(RefundPaymentV1::fromArray($envelope->data));
    }
}
