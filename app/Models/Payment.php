<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ChargeData;
use App\Enums\FailureReason;
use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Shopwise\Contracts\Events\Payments\PaymentFailedV1;
use Shopwise\Contracts\Events\Payments\PaymentRequiresActionV1;
use Shopwise\Contracts\Events\Payments\PaymentSucceededV1;
use Shopwise\Contracts\Events\Payments\RefundedV1;

/**
 * One Stripe PaymentIntent per checkout. Amounts are minor units.
 *
 * @property string $id
 * @property string $checkout_id
 * @property string $customer_id
 * @property int $amount
 * @property string $currency
 * @property string $payment_method_id
 * @property PaymentStatus $status
 * @property string|null $stripe_payment_intent_id
 * @property FailureReason|null $failure_reason
 * @property string|null $failure_message
 * @property string|null $stripe_refund_id
 * @property string|null $refund_reason
 * @property Carbon|null $succeeded_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $refunded_at
 * @property string|null $client_secret
 * @property Carbon|null $action_required_at
 * @property Carbon|null $action_expires_at
 * @property int|null $refunded_amount
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['checkout_id', 'customer_id', 'amount', 'currency', 'payment_method_id', 'status'])]
// The client secret goes to checkout only through payments.payment-requires-action.
#[Hidden(['client_secret'])]
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * What the gateway is asked to charge for this payment (also used to find the intent when the answer was lost).
     */
    public function chargeData(): ChargeData
    {
        return new ChargeData(
            amount: $this->amount,
            currency: $this->currency,
            paymentMethodId: $this->payment_method_id,
            idempotencyKey: "checkout-{$this->checkout_id}-pay",
            metadata: ['checkoutId' => $this->checkout_id, 'customerId' => $this->customer_id],
        );
    }

    public function requiresActionEvent(): PaymentRequiresActionV1
    {
        return new PaymentRequiresActionV1(
            paymentId: $this->id,
            checkoutId: $this->checkout_id,
            customerId: $this->customer_id,
            amount: $this->amount,
            currency: $this->currency,
            stripePaymentIntentId: (string) $this->stripe_payment_intent_id,
            clientSecret: (string) $this->client_secret,
            expiresAt: ($this->action_expires_at ?? $this->updated_at)->toIso8601ZuluString(),
            requiredAt: ($this->action_required_at ?? $this->updated_at)->toIso8601ZuluString(),
        );
    }

    public function succeededEvent(): PaymentSucceededV1
    {
        return new PaymentSucceededV1(
            paymentId: $this->id,
            checkoutId: $this->checkout_id,
            customerId: $this->customer_id,
            amount: $this->amount,
            currency: $this->currency,
            stripePaymentIntentId: (string) $this->stripe_payment_intent_id,
            succeededAt: ($this->succeeded_at ?? $this->updated_at)->toIso8601ZuluString(),
        );
    }

    public function failedEvent(): PaymentFailedV1
    {
        return new PaymentFailedV1(
            paymentId: $this->id,
            checkoutId: $this->checkout_id,
            customerId: $this->customer_id,
            amount: $this->amount,
            currency: $this->currency,
            reason: ($this->failure_reason ?? FailureReason::ProcessingError)->value,
            message: $this->failure_message,
            stripePaymentIntentId: $this->stripe_payment_intent_id,
            failedAt: ($this->failed_at ?? $this->updated_at)->toIso8601ZuluString(),
        );
    }

    public function refundedEvent(): RefundedV1
    {
        return new RefundedV1(
            paymentId: $this->id,
            checkoutId: $this->checkout_id,
            customerId: $this->customer_id,
            amount: $this->refunded_amount ?? $this->amount,
            currency: $this->currency,
            reason: (string) $this->refund_reason,
            stripeRefundId: (string) $this->stripe_refund_id,
            refundedAt: ($this->refunded_at ?? $this->updated_at)->toIso8601ZuluString(),
        );
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'failure_reason' => FailureReason::class,
            'succeeded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
            'action_required_at' => 'immutable_datetime',
            'action_expires_at' => 'immutable_datetime',
            'refunded_amount' => 'integer',
        ];
    }
}
