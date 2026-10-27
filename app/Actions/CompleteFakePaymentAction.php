<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IntentResult;
use App\Enums\FailureReason;
use App\Enums\IntentStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

final readonly class CompleteFakePaymentAction
{
    public function __construct(private SettlePayment $settlePayment) {}

    /**
     * Stands in for the customer finishing 3-D Secure plus Stripe's webhook, for the fake gateway only (local runs
     * and the cluster E2E without Stripe keys). Settles a `requires_action` payment as succeeded or failed with
     * `authentication_required`, exactly as payment_intent.succeeded / payment_intent.payment_failed would. A payment
     * that is no longer waiting is returned unchanged.
     */
    public function handle(Payment $payment, bool $succeeded): Payment
    {
        return DB::transaction(function () use ($payment, $succeeded): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::RequiresAction) {
                return $payment;
            }

            $intentId = (string) $payment->stripe_payment_intent_id;

            $this->settlePayment->handle($payment, $succeeded
                ? new IntentResult($intentId, IntentStatus::Succeeded)
                : new IntentResult($intentId, IntentStatus::Failed, FailureReason::AuthenticationRequired, 'We are unable to authenticate your payment method.'));

            return $payment;
        });
    }

    public static function isEnabled(): bool
    {
        return config('payments.gateway') === 'fake';
    }
}
