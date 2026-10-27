<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FailureReason;
use App\Enums\PaymentStatus;
use App\Gateways\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

final readonly class ExpirePayment
{
    public function __construct(
        private PaymentGateway $gateway,
        private SettlePayment $settlePayment,
        private PublishPaymentOutcome $publishOutcome,
    ) {}

    /**
     * Ends a payment that is still processing after the deadline (ADR-0004: a reply within 5 minutes). Asks Stripe
     * first, so an intent that did finish is settled with its real outcome; otherwise cancels it and fails the
     * payment with `timeout`.
     */
    public function handle(string $paymentId): void
    {
        DB::transaction(function () use ($paymentId): void {
            $payment = Payment::query()->lockForUpdate()->find($paymentId);
            if (! $payment instanceof Payment || $payment->status !== PaymentStatus::Processing) {
                return;
            }

            if ($payment->stripe_payment_intent_id === null) {
                $payment->status = PaymentStatus::Failed;
                $payment->failure_reason = FailureReason::Timeout;
                $payment->failed_at = now();
                $payment->save();
                $this->publishOutcome->handle($payment);

                return;
            }

            $intent = $this->gateway->retrieve($payment->stripe_payment_intent_id);
            if ($this->settlePayment->handle($payment, $intent, FailureReason::Timeout)) {
                return;
            }

            $this->settlePayment->handle($payment, $this->gateway->cancel($payment->stripe_payment_intent_id), FailureReason::Timeout);
        });
    }
}
