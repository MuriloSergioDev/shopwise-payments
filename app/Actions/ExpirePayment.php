<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IntentResult;
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
     * Ends a payment that is still pending after its deadline (ADR-0004: 5 minutes, or `action_expires_at` while
     * 3-D Secure waits). Asks Stripe first, so an intent that did finish is settled with its real outcome; otherwise
     * cancels it and fails the payment with `timeout`. A payment whose charge answer was lost is looked up first.
     */
    public function handle(string $paymentId): void
    {
        DB::transaction(function () use ($paymentId): void {
            $payment = Payment::query()->lockForUpdate()->find($paymentId);
            if (! $payment instanceof Payment || ! $payment->status->isPending()) {
                return;
            }

            $intent = $payment->stripe_payment_intent_id === null
                ? $this->gateway->find($payment->chargeData())
                : $this->gateway->retrieve($payment->stripe_payment_intent_id);

            if (! $intent instanceof IntentResult) {
                $payment->status = PaymentStatus::Failed;
                $payment->failure_reason = FailureReason::Timeout;
                $payment->failed_at = now();
                $payment->save();
                $this->publishOutcome->handle($payment);

                return;
            }

            if ($this->settlePayment->handle($payment, $intent, FailureReason::Timeout) || $this->hasTimeLeft($payment)) {
                return;
            }

            $this->settlePayment->handle($payment, $this->gateway->cancel($intent->intentId), FailureReason::Timeout);
        });
    }

    /**
     * The intent has just turned out to wait for 3-D Secure: it gets until `action_expires_at`.
     */
    private function hasTimeLeft(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::RequiresAction && $payment->action_expires_at?->isFuture() === true;
    }
}
