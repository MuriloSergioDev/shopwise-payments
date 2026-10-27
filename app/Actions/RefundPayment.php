<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FailureReason;
use App\Enums\PaymentStatus;
use App\Gateways\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Shopwise\Contracts\Commands\Payments\RefundPaymentV1;
use Shopwise\Contracts\Events\Payments\PaymentFailedV1;
use Shopwise\Kit\Messaging\Outbox;

final readonly class RefundPayment
{
    public function __construct(
        private PaymentGateway $gateway,
        private SettlePayment $settlePayment,
        private PublishPaymentOutcome $publishOutcome,
    ) {}

    /**
     * Undoes the checkout's payment (ADR-0004 compensation): refunds it when it succeeded, cancels the intent when
     * it is not final, and re-publishes the outcome when it already failed or was refunded. Always replies
     * payments.refunded or payments.payment-failed.
     */
    public function handle(RefundPaymentV1 $command): void
    {
        DB::transaction(function () use ($command): void {
            $payment = Payment::query()->where('checkout_id', $command->checkoutId)->lockForUpdate()->first();

            if (! $payment instanceof Payment) {
                $this->replyNoPayment($command);

                return;
            }

            if ($payment->status === PaymentStatus::Processing && $this->cancel($payment) && $this->hasFailed($payment)) {
                return;
            }

            if ($payment->status === PaymentStatus::Succeeded) {
                $payment->stripe_refund_id = $this->gateway->refund((string) $payment->stripe_payment_intent_id, "checkout-{$command->checkoutId}-refund");
                $payment->refund_reason = $command->reason;
                $payment->refunded_at = now();
                $payment->status = PaymentStatus::Refunded;
                $payment->save();
            }

            $this->publishOutcome->handle($payment);
        });
    }

    /**
     * Cancels a processing payment. If Stripe says it succeeded meanwhile, it is settled as succeeded and the caller
     * refunds it.
     *
     * @return bool whether the settled outcome was already published
     */
    private function cancel(Payment $payment): bool
    {
        if ($payment->stripe_payment_intent_id === null) {
            $payment->status = PaymentStatus::Failed;
            $payment->failure_reason = FailureReason::Canceled;
            $payment->failed_at = now();
            $payment->save();

            return false;
        }

        return $this->settlePayment->handle($payment, $this->gateway->cancel($payment->stripe_payment_intent_id));
    }

    /**
     * Re-reads the status after cancel() changed it (a separate method so static analysis does not keep the narrowed type).
     */
    private function hasFailed(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Failed;
    }

    /**
     * Checkout may compensate a payment that was never created (the request timed out before payments saw it).
     * The schema requires customerId, amount and currency, which the refund command does not carry.
     */
    private function replyNoPayment(RefundPaymentV1 $command): void
    {
        $event = new PaymentFailedV1(
            paymentId: null,
            checkoutId: $command->checkoutId,
            customerId: '',
            amount: 0,
            currency: config()->string('payments.currency'),
            reason: FailureReason::Canceled->value,
            message: null,
            stripePaymentIntentId: null,
            failedAt: Carbon::now('UTC')->toIso8601ZuluString(),
        );

        Outbox::record(PaymentFailedV1::TYPE, $command->checkoutId, $event->toArray(), PaymentFailedV1::SCHEMA);
    }
}
