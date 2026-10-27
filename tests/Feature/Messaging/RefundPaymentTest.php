<?php

declare(strict_types=1);

use App\Enums\FailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Shopwise\Contracts\Events\Payments\PaymentFailedV1;
use Shopwise\Contracts\Events\Payments\PaymentSucceededV1;
use Shopwise\Contracts\Events\Payments\RefundedV1;
use Shopwise\Kit\Messaging\DispatchResult;
use Shopwise\Kit\Messaging\MessageDispatcher;

it('refunds a succeeded payment once with its idempotency key and replies refunded', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->succeeded()->create(['checkout_id' => CHECKOUT_ID, 'amount' => 5220]);
    $envelope = contractExample('payments.refund-payment.v1');
    $dispatcher = app(MessageDispatcher::class);

    expect($dispatcher->dispatch($envelope, 'payments.refund-payment'))->toBe(DispatchResult::Handled)
        ->and($dispatcher->dispatch($envelope, 'payments.refund-payment'))->toBe(DispatchResult::Duplicate);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refund_reason)->toBe('order_rejected')
        ->and($gateway->calls)->toBe([[
            'method' => 'refund',
            'intentId' => $payment->stripe_payment_intent_id,
            'idempotencyKey' => 'checkout-'.CHECKOUT_ID.'-refund',
        ]])
        ->and(recordedEvents(RefundedV1::TYPE))->toEqual([[
            'paymentId' => $payment->id,
            'checkoutId' => CHECKOUT_ID,
            'customerId' => $payment->customer_id,
            'amount' => 5220,
            'currency' => 'USD',
            'reason' => 'order_rejected',
            'stripeRefundId' => $payment->stripe_refund_id,
            'refundedAt' => $payment->refunded_at?->toIso8601ZuluString(),
        ]]);
});

it('re-publishes refunded without refunding twice when the command is re-sent', function (): void {
    $gateway = fakeGateway();
    Payment::factory()->succeeded()->create(['checkout_id' => CHECKOUT_ID]);
    $dispatcher = app(MessageDispatcher::class);

    $dispatcher->dispatch(contractExample('payments.refund-payment.v1'), 'payments.refund-payment');
    $dispatcher->dispatch(contractExample('payments.refund-payment.v1', id: '01JBCDEFGHJKMNPQRSTVWXYZ98'), 'payments.refund-payment');

    $events = recordedEvents(RefundedV1::TYPE);
    expect($gateway->methodsCalled())->toBe(['refund'])
        ->and($events)->toHaveCount(2)
        ->and($events[1])->toBe($events[0]);
});

it('cancels a processing payment and replies payment-failed once', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->processing()->create(['checkout_id' => CHECKOUT_ID]);

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1', ['reason' => 'timeout']), 'payments.refund-payment');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe(FailureReason::Canceled)
        ->and($gateway->methodsCalled())->toBe(['cancel'])
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(PaymentFailedV1::TYPE)[0]['reason'])->toBe('canceled');
});

it('refunds a processing payment that turns out to have succeeded', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->processing('pi_fake_succeeded')->create(['checkout_id' => CHECKOUT_ID]);

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1'), 'payments.refund-payment');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($gateway->methodsCalled())->toBe(['cancel', 'refund'])
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(RefundedV1::TYPE))->toHaveCount(1);
});

it('re-publishes payment-failed for a payment that already failed', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->failed()->create(['checkout_id' => CHECKOUT_ID]);

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1'), 'payments.refund-payment');

    expect($gateway->calls)->toBe([])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(PaymentFailedV1::TYPE)[0]['reason'])->toBe('card_declined');
});

it('replies payment-failed with a null paymentId when the checkout never had a payment', function (): void {
    fakeGateway();

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1'), 'payments.refund-payment');

    $failed = recordedEvents(PaymentFailedV1::TYPE);
    expect(Payment::query()->count())->toBe(0)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0])->toMatchArray([
            'paymentId' => null,
            'checkoutId' => CHECKOUT_ID,
            'amount' => 0,
            'currency' => 'USD',
            'reason' => 'canceled',
            'message' => null,
            'stripePaymentIntentId' => null,
        ]);
});
