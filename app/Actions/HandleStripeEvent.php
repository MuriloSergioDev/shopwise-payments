<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IntentResult;
use App\Data\WebhookEvent;
use App\Enums\PaymentStatus;
use App\Gateways\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopwise\Contracts\Events\Payments\RefundedV1;
use Shopwise\Kit\Messaging\Outbox;

final readonly class HandleStripeEvent
{
    /** Event types we act on; everything else is acknowledged and ignored. */
    public const array HANDLED = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'charge.refunded',
    ];

    public function __construct(
        private SettlePayment $settlePayment,
        private PaymentGateway $gateway,
    ) {}

    /**
     * Applies a verified webhook event once (deduped on Stripe's event id). The intent's outcome only counts while
     * the payment is still pending, so an outcome the synchronous confirm already published is not repeated; after
     * payments.payment-requires-action it is the webhook that finishes the payment.
     */
    public function handle(WebhookEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $fresh = DB::table('stripe_events')->insertOrIgnore([
                'id' => $event->id,
                'type' => $event->type,
                'received_at' => now(),
            ]);

            if ($fresh === 0 || ! in_array($event->type, self::HANDLED, true) || $event->intentId === null) {
                return;
            }

            $payment = $this->findPayment($event);
            if (! $payment instanceof Payment) {
                // Not ours, or the charge's transaction has not committed yet; the confirm result or the deadline decides.
                Log::info('Stripe event for an unknown PaymentIntent ignored', ['event' => $event->id, 'intent' => $event->intentId]);

                return;
            }

            if ($event->intent instanceof IntentResult) {
                $this->settlePayment->handle($payment, $event->intent);

                return;
            }

            $this->recordExternalRefund($payment, $event);
        });
    }

    /**
     * By intent id, or by the checkout in the intent's metadata when the charge's answer was lost (no intent id yet).
     */
    private function findPayment(WebhookEvent $event): ?Payment
    {
        $payment = Payment::query()->where('stripe_payment_intent_id', $event->intentId)->lockForUpdate()->first();

        if ($payment === null && $event->checkoutId !== null) {
            $payment = Payment::query()->where('checkout_id', $event->checkoutId)->whereNull('stripe_payment_intent_id')->lockForUpdate()->first();
        }

        return $payment;
    }

    /**
     * charge.refunded for a refund payments did not request (Stripe dashboard or API): published as
     * payments.refunded with reason external and the amount this refund added (a partial refund is possible).
     * A saga refund is recognised by its reason; the refund command holds the row lock until it is recorded.
     */
    private function recordExternalRefund(Payment $payment, WebhookEvent $event): void
    {
        $ours = $payment->status === PaymentStatus::Refunded && $payment->refund_reason !== RefundPayment::EXTERNAL;
        if ($ours || ! in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::Refunded], true)) {
            return;
        }

        $total = $event->amountRefunded ?? $payment->amount;
        $added = $total - ($payment->refunded_amount ?? 0);
        if ($added <= 0) {
            return;
        }

        $refundId = $event->refundId ?? $this->gateway->latestRefundId((string) $payment->stripe_payment_intent_id) ?? '';
        Log::warning('Payment refunded outside the saga', ['payment' => $payment->id, 'amount' => $added, 'refund' => $refundId]);

        $payment->status = PaymentStatus::Refunded;
        $payment->refund_reason = RefundPayment::EXTERNAL;
        $payment->refunded_amount = $total;
        $payment->refunded_at = now();
        $payment->stripe_refund_id = $refundId;
        $payment->save();

        $refunded = new RefundedV1(
            paymentId: $payment->id,
            checkoutId: $payment->checkout_id,
            customerId: $payment->customer_id,
            amount: $added,
            currency: $payment->currency,
            reason: RefundPayment::EXTERNAL,
            stripeRefundId: $refundId,
            refundedAt: Carbon::now('UTC')->toIso8601ZuluString(),
        );

        Outbox::record(RefundedV1::TYPE, $payment->id, $refunded->toArray(), RefundedV1::SCHEMA);
    }
}
