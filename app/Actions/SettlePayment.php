<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IntentResult;
use App\Enums\FailureReason;
use App\Enums\IntentStatus;
use App\Enums\PaymentStatus;
use App\Gateways\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

final readonly class SettlePayment
{
    public function __construct(
        private PaymentGateway $gateway,
        private PublishPaymentOutcome $publishOutcome,
    ) {}

    /**
     * Applies what Stripe says about the intent to a processing payment and publishes the outcome once.
     * The synchronous confirm, the webhook and the deadline all end up here; whichever comes first decides and the
     * others find the payment no longer processing. The caller locks the row. `$canceledAs` is the reason recorded
     * when the intent ended canceled (the deadline passes `timeout`).
     *
     * @return bool whether this call decided the outcome
     */
    public function handle(Payment $payment, IntentResult $intent, FailureReason $canceledAs = FailureReason::Canceled): bool
    {
        if ($payment->status !== PaymentStatus::Processing) {
            return false;
        }

        return DB::transaction(function () use ($payment, $intent, $canceledAs): bool {
            $payment->stripe_payment_intent_id = $intent->intentId;

            if ($intent->status === IntentStatus::RequiresAction) {
                // 3-D Secure is out of scope (ADR-0004): cancel the intent and fail the payment.
                $this->gateway->cancel($intent->intentId);
                $this->fail($payment, FailureReason::AuthenticationRequired, 'This card needs authentication, which this shop does not support.');
            } else {
                match ($intent->status) {
                    IntentStatus::Succeeded => $this->succeed($payment),
                    IntentStatus::Failed => $this->fail($payment, $intent->failureReason ?? FailureReason::ProcessingError, $intent->failureMessage),
                    IntentStatus::Canceled => $this->fail($payment, $canceledAs, null),
                    default => null,
                };
            }

            $payment->save();

            if ($payment->status === PaymentStatus::Processing) {
                return false;
            }

            $this->publishOutcome->handle($payment);

            return true;
        });
    }

    private function succeed(Payment $payment): void
    {
        $payment->status = PaymentStatus::Succeeded;
        $payment->succeeded_at = now();
    }

    private function fail(Payment $payment, FailureReason $reason, ?string $message): void
    {
        $payment->status = PaymentStatus::Failed;
        $payment->failure_reason = $reason;
        $payment->failure_message = $message;
        $payment->failed_at = now();
    }
}
