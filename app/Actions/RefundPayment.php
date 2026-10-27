<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IntentResult;
use App\Enums\FailureReason;
use App\Enums\PaymentStatus;
use App\Gateways\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopwise\Contracts\Commands\Payments\RefundPaymentV1;
use Shopwise\Contracts\Events\Payments\PaymentFailedV1;
use Shopwise\Kit\Messaging\Outbox;

final readonly class RefundPayment
{
    /** refund_reason of a refund made outside the saga (charge.refunded we did not ask for). */
    public const string EXTERNAL = 'external';

    public function __construct(
        private PaymentGateway $gateway,
        private SettlePayment $settlePayment,
        private PublishPaymentOutcome $publishOutcome,
    ) {}

    /**
     * Undoes the checkout's payment (ADR-0004 compensation): refunds it (or what an external refund left) when it
     * succeeded, cancels the intent when it is not final (3-D Secure pending included), and re-publishes the outcome
     * when it already failed or was refunded. Replies payments.refunded or payments.payment-failed.
     *
     * When the command carries customerId, amount or currency and they disagree with the payment, nothing is refunded
     * and nothing is replied: that is a bug in checkout, logged as an error.
     */
    public function handle(RefundPaymentV1 $command): void
    {
        DB::transaction(function () use ($command): void {
            $payment = Payment::query()->where('checkout_id', $command->checkoutId)->lockForUpdate()->first();

            if (! $payment instanceof Payment) {
                $this->replyNoPayment($command);

                return;
            }

            if (! $this->matches($payment, $command)) {
                Log::error('refund-payment does not match the payment; not refunded', [
                    'payment' => $payment->id,
                    'checkoutId' => $command->checkoutId,
                    'expected' => ['customerId' => $payment->customer_id, 'amount' => $payment->amount, 'currency' => $payment->currency],
                    'received' => ['customerId' => $command->customerId, 'amount' => $command->amount, 'currency' => $command->currency],
                ]);

                return;
            }

            if ($payment->status->isPending() && $this->cancel($payment) && $this->hasFailed($payment)) {
                return;
            }

            if ($this->isRefundable($payment)) {
                $this->refund($payment, $command);
            }

            $this->publishOutcome->handle($payment);
        });
    }

    private function matches(Payment $payment, RefundPaymentV1 $command): bool
    {
        return ($command->customerId === null || $command->customerId === $payment->customer_id)
            && ($command->amount === null || $command->amount === $payment->amount)
            && ($command->currency === null || $command->currency === $payment->currency);
    }

    /**
     * Cancels a pending payment. If Stripe says it succeeded meanwhile, it is settled as succeeded and the caller
     * refunds it. A payment whose charge answer was lost is looked up first, so money Stripe did take is refunded.
     *
     * @return bool whether the settled outcome was already published
     */
    private function cancel(Payment $payment): bool
    {
        if ($payment->stripe_payment_intent_id === null) {
            $found = $this->gateway->find($payment->chargeData());

            if (! $found instanceof IntentResult) {
                $payment->status = PaymentStatus::Failed;
                $payment->failure_reason = FailureReason::Canceled;
                $payment->failed_at = now();
                $payment->save();

                return false;
            }

            if ($this->settlePayment->handle($payment, $found)) {
                return true;
            }
        }

        return $this->settlePayment->handle($payment, $this->gateway->cancel((string) $payment->stripe_payment_intent_id));
    }

    /**
     * Re-reads the status after cancel() changed it (a separate method so static analysis does not keep the narrowed type).
     */
    private function hasFailed(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Failed;
    }

    /**
     * Succeeded, or refunded only from outside the saga: the saga's refund still has to happen (and be replied to).
     */
    private function isRefundable(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Succeeded
            || ($payment->status === PaymentStatus::Refunded && $payment->refund_reason === self::EXTERNAL);
    }

    private function refund(Payment $payment, RefundPaymentV1 $command): void
    {
        if (($payment->refunded_amount ?? 0) < $payment->amount) {
            $payment->stripe_refund_id = $this->gateway->refund((string) $payment->stripe_payment_intent_id, "checkout-{$command->checkoutId}-refund");
        }

        $payment->refund_reason = $command->reason;
        $payment->refunded_amount = $payment->amount;
        $payment->refunded_at = now();
        $payment->status = PaymentStatus::Refunded;
        $payment->save();
    }

    /**
     * Checkout may compensate a payment that was never created (the request timed out before payments saw it).
     * The schema requires customerId, amount and currency: taken from the command (checkout always sends them),
     * placeholders for an older checkout that does not.
     */
    private function replyNoPayment(RefundPaymentV1 $command): void
    {
        $event = new PaymentFailedV1(
            paymentId: null,
            checkoutId: $command->checkoutId,
            customerId: $command->customerId ?? '',
            amount: $command->amount ?? 0,
            currency: $command->currency ?? config()->string('payments.currency'),
            reason: FailureReason::Canceled->value,
            message: null,
            stripePaymentIntentId: null,
            failedAt: Carbon::now('UTC')->toIso8601ZuluString(),
        );

        Outbox::record(PaymentFailedV1::TYPE, $command->checkoutId, $event->toArray(), PaymentFailedV1::SCHEMA);
    }
}
