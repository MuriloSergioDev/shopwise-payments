<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Shopwise\Contracts\Events\Payments\PaymentFailedV1;
use Shopwise\Contracts\Events\Payments\PaymentRequiresActionV1;
use Shopwise\Contracts\Events\Payments\PaymentSucceededV1;
use Shopwise\Contracts\Events\Payments\RefundedV1;
use Shopwise\Kit\Messaging\Outbox;

final readonly class PublishPaymentOutcome
{
    /**
     * Records the event for the payment's current outcome (keyed by paymentId). Used when a payment is settled and
     * again whenever checkout repeats a command (ADR-0004: a new envelope id, same data). While the customer has a
     * 3-D Secure action to finish that is payments.payment-requires-action; nothing while processing.
     */
    public function handle(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            match ($payment->status) {
                PaymentStatus::Succeeded => Outbox::record(PaymentSucceededV1::TYPE, $payment->id, $payment->succeededEvent()->toArray(), PaymentSucceededV1::SCHEMA),
                PaymentStatus::Failed => Outbox::record(PaymentFailedV1::TYPE, $payment->id, $payment->failedEvent()->toArray(), PaymentFailedV1::SCHEMA),
                PaymentStatus::Refunded => Outbox::record(RefundedV1::TYPE, $payment->id, $payment->refundedEvent()->toArray(), RefundedV1::SCHEMA),
                PaymentStatus::RequiresAction => Outbox::record(PaymentRequiresActionV1::TYPE, $payment->id, $payment->requiresActionEvent()->toArray(), PaymentRequiresActionV1::SCHEMA),
                PaymentStatus::Processing => null,
            };
        });
    }
}
