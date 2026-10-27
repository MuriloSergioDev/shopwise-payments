<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IntentResult;
use App\Data\WebhookEvent;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class HandleStripeEvent
{
    /** Event types we act on; everything else is acknowledged and ignored. */
    public const array HANDLED = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'charge.refunded',
    ];

    public function __construct(private SettlePayment $settlePayment) {}

    /**
     * Applies a verified webhook event once (deduped on Stripe's event id). The intent's outcome only counts while
     * the payment is still processing, so an outcome the synchronous confirm already published is not repeated.
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

            $payment = Payment::query()->where('stripe_payment_intent_id', $event->intentId)->lockForUpdate()->first();
            if (! $payment instanceof Payment) {
                // Not ours, or the charge's transaction has not committed yet; the confirm result or the deadline decides.
                Log::info('Stripe event for an unknown PaymentIntent ignored', ['event' => $event->id, 'intent' => $event->intentId]);

                return;
            }

            if ($event->intent instanceof IntentResult) {
                $this->settlePayment->handle($payment, $event->intent);

                return;
            }

            if ($payment->status === PaymentStatus::Succeeded) {
                // charge.refunded for a refund made outside the saga (Stripe dashboard): no saga reason fits, no event.
                Log::warning('Payment refunded outside the saga', ['payment' => $payment->id]);
                $payment->status = PaymentStatus::Refunded;
                $payment->refunded_at = now();
                $payment->save();
            }
        });
    }
}
