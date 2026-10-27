<?php

declare(strict_types=1);

use App\Enums\FailureReason;
use App\Enums\PaymentStatus;
use App\Gateways\PaymentGateway;
use App\Models\Payment;
use Shopwise\Contracts\Events\Payments\PaymentFailedV1;
use Shopwise\Contracts\Events\Payments\PaymentRequiresActionV1;
use Shopwise\Contracts\Events\Payments\PaymentSucceededV1;
use Shopwise\Contracts\Events\Payments\RefundedV1;
use Shopwise\Kit\Messaging\DispatchResult;
use Shopwise\Kit\Messaging\MessageDispatcher;
use Shopwise\Kit\Messaging\Publisher;
use Shopwise\Kit\Testing\FakePublisher;

it('charges the checkout once with its idempotency key and replies payment-succeeded', function (): void {
    $gateway = fakeGateway();
    $envelope = contractExample('payments.request-payment.v1');
    $dispatcher = app(MessageDispatcher::class);

    expect($dispatcher->dispatch($envelope, 'payments.request-payment'))->toBe(DispatchResult::Handled)
        ->and($dispatcher->dispatch($envelope, 'payments.request-payment'))->toBe(DispatchResult::Duplicate);

    $payment = Payment::query()->sole();
    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->checkout_id)->toBe(CHECKOUT_ID)
        ->and($payment->amount)->toBe(5220)
        ->and($gateway->calls)->toHaveCount(1)
        ->and($gateway->calls[0]['idempotencyKey'])->toBe('checkout-'.CHECKOUT_ID.'-pay')
        ->and($gateway->calls[0]['charge']?->metadata)->toBe(['checkoutId' => CHECKOUT_ID, 'customerId' => '01JBCDEFGHJKMNPQRSTVWXYZ11']);

    expect(recordedEvents(PaymentSucceededV1::TYPE))->toEqual([[
        'paymentId' => $payment->id,
        'checkoutId' => CHECKOUT_ID,
        'customerId' => '01JBCDEFGHJKMNPQRSTVWXYZ11',
        'amount' => 5220,
        'currency' => 'USD',
        'stripePaymentIntentId' => $payment->stripe_payment_intent_id,
        'succeededAt' => $payment->succeeded_at?->toIso8601ZuluString(),
    ]]);
});

it('re-publishes the outcome without charging again when checkout re-sends the command', function (): void {
    $gateway = fakeGateway();
    $dispatcher = app(MessageDispatcher::class);

    $dispatcher->dispatch(contractExample('payments.request-payment.v1'), 'payments.request-payment');
    $dispatcher->dispatch(contractExample('payments.request-payment.v1', id: '01JBCDEFGHJKMNPQRSTVWXYZ99'), 'payments.request-payment');

    $events = recordedEvents(PaymentSucceededV1::TYPE);
    expect(Payment::query()->count())->toBe(1)
        ->and($gateway->methodsCalled())->toBe(['charge'])
        ->and($events)->toHaveCount(2)
        ->and($events[1])->toBe($events[0]);
});

it('replies payment-failed when the card is declined', function (string $paymentMethod, FailureReason $reason): void {
    fakeGateway();

    app(MessageDispatcher::class)->dispatch(contractExample('payments.request-payment.v1', ['paymentMethodId' => $paymentMethod]), 'payments.request-payment');

    $payment = Payment::query()->sole();
    expect($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe($reason)
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toBe([]);

    $failed = recordedEvents(PaymentFailedV1::TYPE);
    expect($failed)->toHaveCount(1)
        ->and($failed[0])->toMatchArray([
            'paymentId' => $payment->id,
            'checkoutId' => CHECKOUT_ID,
            'amount' => 5220,
            'reason' => $reason->value,
            'stripePaymentIntentId' => $payment->stripe_payment_intent_id,
        ])
        ->and($failed[0]['message'])->toBeString();
})->with([
    'declined' => ['pm_card_chargeDeclined', FailureReason::CardDeclined],
    'insufficient funds' => ['pm_card_chargeDeclinedInsufficientFunds', FailureReason::InsufficientFunds],
]);

it('keeps an intent that needs 3-D Secure and replies payment-requires-action with its client secret', function (string $paymentMethod): void {
    $this->freezeTime();
    $gateway = fakeGateway();

    app(MessageDispatcher::class)->dispatch(contractExample('payments.request-payment.v1', ['paymentMethodId' => $paymentMethod]), 'payments.request-payment');

    $payment = Payment::query()->sole();
    expect($payment->status)->toBe(PaymentStatus::RequiresAction)
        ->and($gateway->methodsCalled())->toBe(['charge'])
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toBe([])
        ->and(recordedEvents(PaymentRequiresActionV1::TYPE))->toEqual([[
            'paymentId' => $payment->id,
            'checkoutId' => CHECKOUT_ID,
            'customerId' => '01JBCDEFGHJKMNPQRSTVWXYZ11',
            'amount' => 5220,
            'currency' => 'USD',
            'stripePaymentIntentId' => $payment->stripe_payment_intent_id,
            'clientSecret' => $payment->stripe_payment_intent_id.'_secret_fake',
            'expiresAt' => now()->addMinutes(10)->toIso8601ZuluString(),
            'requiredAt' => now()->toIso8601ZuluString(),
        ]]);
})->with(['pm_card_threeDSecure2Required', 'pm_card_authenticationRequired']);

it('re-publishes payment-requires-action while the customer has not finished 3-D Secure', function (): void {
    $gateway = fakeGateway();
    $dispatcher = app(MessageDispatcher::class);

    $dispatcher->dispatch(contractExample('payments.request-payment.v1', ['paymentMethodId' => 'pm_card_threeDSecure2Required']), 'payments.request-payment');
    $dispatcher->dispatch(contractExample('payments.request-payment.v1', ['paymentMethodId' => 'pm_card_threeDSecure2Required'], '01JBCDEFGHJKMNPQRSTVWXYZ99'), 'payments.request-payment');

    $events = recordedEvents(PaymentRequiresActionV1::TYPE);
    expect($gateway->methodsCalled())->toBe(['charge', 'retrieve'])
        ->and($events)->toHaveCount(2)
        ->and($events[1])->toBe($events[0]);
});

it('keeps the payment when Stripe may have charged but its answer was lost, and finds the intent on a re-send', function (): void {
    $gateway = fakeGateway();
    $dispatcher = app(MessageDispatcher::class);

    expect($dispatcher->dispatch(contractExample('payments.request-payment.v1', ['paymentMethodId' => 'pm_fake_lostResponse']), 'payments.request-payment'))
        ->toBe(DispatchResult::Handled);

    $payment = Payment::query()->sole();
    expect($payment->status)->toBe(PaymentStatus::Processing)
        ->and($payment->stripe_payment_intent_id)->toBeNull()
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toBe([]);

    $dispatcher->dispatch(contractExample('payments.request-payment.v1', ['paymentMethodId' => 'pm_fake_lostResponse'], '01JBCDEFGHJKMNPQRSTVWXYZ99'), 'payments.request-payment');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->stripe_payment_intent_id)->toStartWith('pi_fake_succeeded_')
        ->and($gateway->methodsCalled())->toBe(['charge', 'find'])
        ->and($gateway->calls[1]['idempotencyKey'])->toBe('checkout-'.CHECKOUT_ID.'-pay')
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toHaveCount(1);
});

it('leaves a payment that is not final processing and replies nothing yet', function (): void {
    fakeGateway();

    app(MessageDispatcher::class)->dispatch(contractExample('payments.request-payment.v1', ['paymentMethodId' => 'pm_fake_processing']), 'payments.request-payment');

    $payment = Payment::query()->sole();
    expect($payment->status)->toBe(PaymentStatus::Processing)
        ->and($payment->stripe_payment_intent_id)->toStartWith('pi_fake_pending_')
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toBe([])
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toBe([]);
});

it('asks Stripe again when a re-sent command finds the payment still processing', function (): void {
    $gateway = fakeGateway();
    Payment::factory()->processing('pi_fake_succeeded')->create(['checkout_id' => CHECKOUT_ID]);

    app(MessageDispatcher::class)->dispatch(contractExample('payments.request-payment.v1'), 'payments.request-payment');

    expect($gateway->methodsCalled())->toBe(['retrieve'])
        ->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Succeeded)
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toHaveCount(1);
});

it('re-publishes refunded when the payment was already refunded', function (): void {
    $gateway = fakeGateway();
    Payment::factory()->refunded()->create(['checkout_id' => CHECKOUT_ID]);

    app(MessageDispatcher::class)->dispatch(contractExample('payments.request-payment.v1'), 'payments.request-payment');

    expect($gateway->calls)->toBe([])
        ->and(recordedEvents(RefundedV1::TYPE))->toHaveCount(1);
});

it('rolls back and lets the consumer retry when Stripe refuses the request', function (): void {
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('charge')->twice()->andThrow(new RuntimeException('Stripe is down'));
    app()->instance(PaymentGateway::class, $gateway);
    config(['messaging.max_attempts' => 2]);
    app()->instance(Publisher::class, $publisher = new FakePublisher);

    expect(app(MessageDispatcher::class)->dispatch(contractExample('payments.request-payment.v1'), 'payments.request-payment', backoffMs: 0))
        ->toBe(DispatchResult::DeadLettered)
        ->and(Payment::query()->count())->toBe(0);
    $publisher->assertPublished('payments.request-payment.dlq');
});
