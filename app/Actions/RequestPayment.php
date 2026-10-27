<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ChargeData;
use App\Enums\PaymentStatus;
use App\Gateways\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Shopwise\Contracts\Commands\Payments\RequestPaymentV1;

final readonly class RequestPayment
{
    public function __construct(
        private PaymentGateway $gateway,
        private SettlePayment $settlePayment,
        private PublishPaymentOutcome $publishOutcome,
    ) {}

    /**
     * Charges the checkout once (ADR-0004). A repeated command does not charge again: it re-publishes the outcome,
     * or asks Stripe again while the payment is still processing. Gateway errors (Stripe down, bad key) throw, so
     * the transaction rolls back and the consumer retries; the Stripe idempotency key makes that safe.
     */
    public function handle(RequestPaymentV1 $command): Payment
    {
        return DB::transaction(function () use ($command): Payment {
            $payment = Payment::query()->where('checkout_id', $command->checkoutId)->lockForUpdate()->first();

            if ($payment instanceof Payment) {
                if ($payment->status !== PaymentStatus::Processing) {
                    $this->publishOutcome->handle($payment);
                } elseif ($payment->stripe_payment_intent_id !== null) {
                    $this->settlePayment->handle($payment, $this->gateway->retrieve($payment->stripe_payment_intent_id));
                }

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

            $intent = $this->gateway->charge(new ChargeData(
                amount: $command->amount,
                currency: $command->currency,
                paymentMethodId: $command->paymentMethodId,
                idempotencyKey: "checkout-{$command->checkoutId}-pay",
                metadata: ['checkoutId' => $command->checkoutId, 'customerId' => $command->customerId],
            ));

            $this->settlePayment->handle($payment, $intent);

            return $payment;
        });
    }
}
