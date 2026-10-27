<?php

declare(strict_types=1);

use App\Enums\FailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Shopwise\Contracts\Events\Payments\PaymentFailedV1;
use Shopwise\Contracts\Events\Payments\PaymentSucceededV1;
use Shopwise\Contracts\Events\Payments\RefundedV1;
use Shopwise\Kit\Messaging\DispatchResult;
use Shopwise\Kit\Messaging\Envelope;
use Shopwise\Kit\Messaging\MessageDispatcher;

it('refunds a succeeded payment once with its idempotency key and replies refunded', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->succeeded()->create(exampleCheckout());
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
    Payment::factory()->succeeded()->create(exampleCheckout());
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
    $payment = Payment::factory()->processing()->create(exampleCheckout());

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1', ['reason' => 'timeout']), 'payments.refund-payment');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe(FailureReason::Canceled)
        ->and($gateway->methodsCalled())->toBe(['cancel'])
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(PaymentFailedV1::TYPE)[0]['reason'])->toBe('canceled');
});

it('refunds a processing payment that turns out to have succeeded', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->processing('pi_fake_succeeded')->create(exampleCheckout());

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1'), 'payments.refund-payment');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($gateway->methodsCalled())->toBe(['cancel', 'refund'])
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(RefundedV1::TYPE))->toHaveCount(1);
});

it('re-publishes payment-failed for a payment that already failed', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->failed()->create(exampleCheckout());

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1'), 'payments.refund-payment');

    expect($gateway->calls)->toBe([])
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(PaymentFailedV1::TYPE)[0]['reason'])->toBe('card_declined');
});

it('replies payment-failed with a null paymentId and the amounts of the command when the checkout never had a payment', function (): void {
    fakeGateway();

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1'), 'payments.refund-payment');

    $failed = recordedEvents(PaymentFailedV1::TYPE);
    expect(Payment::query()->count())->toBe(0)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0])->toMatchArray([
            'paymentId' => null,
            'checkoutId' => CHECKOUT_ID,
            'customerId' => '01JBCDEFGHJKMNPQRSTVWXYZ11',
            'amount' => 5220,
            'currency' => 'USD',
            'reason' => 'canceled',
            'message' => null,
            'stripePaymentIntentId' => null,
        ]);
});

it('falls back to placeholders for a command without customer, amount and currency', function (): void {
    fakeGateway();
    $envelope = contractExample('payments.refund-payment.v1');
    $payload = $envelope->toArray();
    unset($payload['data']['customerId'], $payload['data']['amount'], $payload['data']['currency']);

    app(MessageDispatcher::class)->dispatch(Envelope::fromArray($payload), 'payments.refund-payment');

    expect(recordedEvents(PaymentFailedV1::TYPE)[0])->toMatchArray(['customerId' => '', 'amount' => 0, 'currency' => 'USD']);
});

it('neither refunds nor replies when the command disagrees with the payment', function (array $data): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->succeeded()->create(exampleCheckout());
    Log::spy();

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1', $data), 'payments.refund-payment');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($gateway->calls)->toBe([])
        ->and(recordedEvents(RefundedV1::TYPE))->toBe([])
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toBe([]);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'does not match'))->once();
})->with([
    'customer' => [['customerId' => '01JBCDEFGHJKMNPQRSTVWXYZ12']],
    'amount' => [['amount' => 5000]],
    'currency' => [['currency' => 'EUR']],
]);

it('cancels a payment waiting for 3-D Secure and replies payment-failed canceled', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->requiresAction()->create(exampleCheckout());

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1', ['reason' => 'timeout']), 'payments.refund-payment');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe(FailureReason::Canceled)
        ->and($payment->client_secret)->toBeNull()
        ->and($gateway->methodsCalled())->toBe(['cancel'])
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toHaveCount(1);
});

it('finds and refunds a charge whose answer was lost', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->create([...exampleCheckout(), 'payment_method_id' => 'pm_fake_lostResponse']);

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1'), 'payments.refund-payment');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->stripe_payment_intent_id)->toStartWith('pi_fake_succeeded_')
        ->and($gateway->methodsCalled())->toBe(['find', 'refund'])
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(RefundedV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(RefundedV1::TYPE)[0])->toMatchArray(['amount' => 5220, 'reason' => 'order_rejected']);
});

it('fails a payment without an intent when Stripe has none for the checkout', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->create(exampleCheckout());

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1'), 'payments.refund-payment');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe(FailureReason::Canceled)
        ->and($gateway->methodsCalled())->toBe(['find'])
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toHaveCount(1);
});

it('refunds what an external partial refund left and replies with the saga reason', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->refunded('external')->create([...exampleCheckout(), 'refunded_amount' => 1000]);

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1'), 'payments.refund-payment');

    expect($payment->refresh()->refund_reason)->toBe('order_rejected')
        ->and($payment->refunded_amount)->toBe(5220)
        ->and($gateway->methodsCalled())->toBe(['refund'])
        ->and(recordedEvents(RefundedV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(RefundedV1::TYPE)[0])->toMatchArray(['amount' => 5220, 'reason' => 'order_rejected']);
});

it('only replies when an external refund already returned everything', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->refunded('external')->create([...exampleCheckout(), 'refunded_amount' => 5220]);

    app(MessageDispatcher::class)->dispatch(contractExample('payments.refund-payment.v1'), 'payments.refund-payment');

    expect($payment->refresh()->refund_reason)->toBe('order_rejected')
        ->and($gateway->calls)->toBe([])
        ->and(recordedEvents(RefundedV1::TYPE)[0])->toMatchArray(['amount' => 5220, 'reason' => 'order_rejected']);
});
