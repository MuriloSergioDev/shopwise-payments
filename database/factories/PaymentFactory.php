<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'checkout_id' => (string) Str::ulid(),
            'customer_id' => (string) Str::ulid(),
            'amount' => fake()->numberBetween(500, 20000),
            'currency' => 'USD',
            'payment_method_id' => 'pm_card_visa',
            'status' => PaymentStatus::Processing,
        ];
    }

    public function processing(string $intentId = 'pi_fake_pending'): self
    {
        return $this->state(['status' => PaymentStatus::Processing])
            ->afterMaking(function (Payment $payment) use ($intentId): void {
                $payment->stripe_payment_intent_id = $intentId.'_'.Str::lower((string) Str::ulid());
            });
    }

    public function succeeded(): self
    {
        return $this->state(['status' => PaymentStatus::Succeeded])
            ->afterMaking(function (Payment $payment): void {
                $payment->stripe_payment_intent_id = 'pi_fake_succeeded_'.Str::lower((string) Str::ulid());
                $payment->succeeded_at = now();
            });
    }

    public function failed(FailureReason $reason = FailureReason::CardDeclined): self
    {
        return $this->state(['status' => PaymentStatus::Failed])
            ->afterMaking(function (Payment $payment) use ($reason): void {
                $payment->stripe_payment_intent_id = 'pi_fake_failed_'.Str::lower((string) Str::ulid());
                $payment->failure_reason = $reason;
                $payment->failure_message = 'Your card was declined.';
                $payment->failed_at = now();
            });
    }

    public function refunded(string $reason = 'order_rejected'): self
    {
        return $this->succeeded()->state(['status' => PaymentStatus::Refunded])
            ->afterMaking(function (Payment $payment) use ($reason): void {
                $payment->stripe_refund_id = 're_fake_'.Str::lower((string) Str::ulid());
                $payment->refund_reason = $reason;
                $payment->refunded_at = now();
            });
    }
}
