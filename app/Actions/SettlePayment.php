<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IntentResult;
use App\Enums\FailureReason;
use App\Enums\IntentStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class SettlePayment
{
    public function __construct(private PublishPaymentOutcome $publishOutcome) {}

    /**
     * Applies what Stripe says about the intent to a pending payment and publishes the outcome once.
     * The synchronous confirm, the webhook and the deadline all end up here; whichever comes first decides and the
     * others find the payment no longer pending. The caller locks the row. `$canceledAs` is the reason recorded
     * when the intent ended canceled (the deadline passes `timeout`).
     *
     * `requires_action` (3-D Secure) is not an outcome: the payment waits in `requires_action` and
     * payments.payment-requires-action is published once, when it gets there.
     *
     * @return bool whether this call decided the final outcome
     */
    public function handle(Payment $payment, IntentResult $intent, FailureReason $canceledAs = FailureReason::Canceled): bool
    {
        if (! $payment->status->isPending()) {
            return false;
        }

        return DB::transaction(function () use ($payment, $intent, $canceledAs): bool {
            $before = $payment->status;
            $payment->stripe_payment_intent_id = $intent->intentId;

            match ($intent->status) {
                IntentStatus::Succeeded => $this->succeed($payment),
                IntentStatus::Failed => $this->fail($payment, $intent->failureReason ?? FailureReason::ProcessingError, $intent->failureMessage),
                IntentStatus::Canceled => $this->fail($payment, $canceledAs, null),
                IntentStatus::RequiresAction => $this->awaitAction($payment, $intent),
                IntentStatus::Pending => null,
            };

            $payment->save();

            if ($payment->status === $before) {
                return false;
            }

            $this->publishOutcome->handle($payment);

            return ! $payment->status->isPending();
        });
    }

    private function awaitAction(Payment $payment, IntentResult $intent): void
    {
        if ($payment->status === PaymentStatus::RequiresAction) {
            return;
        }

        $created = $intent->createdAt === null ? now() : Carbon::createFromTimestamp($intent->createdAt);

        $payment->status = PaymentStatus::RequiresAction;
        $payment->client_secret = $intent->clientSecret;
        $payment->action_required_at = now();
        $payment->action_expires_at = $created->addSeconds(config()->integer('payments.action_deadline_seconds'));
    }

    private function succeed(Payment $payment): void
    {
        $payment->status = PaymentStatus::Succeeded;
        $payment->succeeded_at = now();
        $payment->client_secret = null;
    }

    private function fail(Payment $payment, FailureReason $reason, ?string $message): void
    {
        $payment->status = PaymentStatus::Failed;
        $payment->failure_reason = $reason;
        $payment->failure_message = $message;
        $payment->failed_at = now();
        $payment->client_secret = null;
    }
}
