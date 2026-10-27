<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IntentResult;
use App\Enums\PaymentStatus;
use App\Gateways\ChargeOutcomeUnknown;
use App\Gateways\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopwise\Contracts\Commands\Payments\RequestPaymentV1;

final readonly class RequestPayment
{
    public function __construct(
        private PaymentGateway $gateway,
        private SettlePayment $settlePayment,
        private PublishPaymentOutcome $publishOutcome,
    ) {}

    /**
     * Charges the checkout once (ADR-0004). A repeated command does not charge again: it re-publishes the outcome
     * (or payments.payment-requires-action while 3-D Secure is pending), or asks Stripe again while the payment is
     * still processing.
     *
     * When Stripe may have charged but no answer came back (ChargeOutcomeUnknown) the payment is kept, processing
     * and without an intent: a re-sent command, a refund command or the deadline finds the intent by its metadata.
     * Other gateway errors (bad key, Stripe refusing the request) throw, so the transaction rolls back and the
     * consumer retries; the Stripe idempotency key makes that safe.
     */
    public function handle(RequestPaymentV1 $command): Payment
    {
        return DB::transaction(function () use ($command): Payment {
            $payment = Payment::query()->where('checkout_id', $command->checkoutId)->lockForUpdate()->first();

            if ($payment instanceof Payment) {
                $this->repeat($payment);

                return $payment;
            }

            $payment = Payment::query()->create([
                'checkout_id' => $command->checkoutId,
                'customer_id' => $command->customerId,
                'amount' => $command->amount,
                'currency' => $command->currency,
                'payment_method_id' => $command->paymentMethodId,
                'status' => PaymentStatus::Processing,
            ]);

            $intent = $this->charge($payment);
            if ($intent instanceof IntentResult) {
                $this->settlePayment->handle($payment, $intent);
            }

            return $payment;
        });
    }

    private function repeat(Payment $payment): void
    {
        if (! $payment->status->isPending()) {
            $this->publishOutcome->handle($payment);

            return;
        }

        $intent = $payment->stripe_payment_intent_id === null
            ? $this->gateway->find($payment->chargeData()) ?? $this->charge($payment)
            : $this->gateway->retrieve($payment->stripe_payment_intent_id);

        $waiting = $payment->status === PaymentStatus::RequiresAction;
        if ($intent instanceof IntentResult) {
            $this->settlePayment->handle($payment, $intent);
        }

        if ($waiting && $this->stillWaiting($payment)) {
            $this->publishOutcome->handle($payment);
        }
    }

    /**
     * Re-reads the status after settling changed it (a separate method so static analysis does not keep the narrowed type).
     */
    private function stillWaiting(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::RequiresAction;
    }

    private function charge(Payment $payment): ?IntentResult
    {
        try {
            return $this->gateway->charge($payment->chargeData());
        } catch (ChargeOutcomeUnknown $e) {
            Log::warning('Charge outcome unknown; the PaymentIntent will be looked up', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
