<?php

declare(strict_types=1);

use App\Enums\FailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Shopwise\Contracts\Events\Payments\PaymentFailedV1;
use Shopwise\Contracts\Events\Payments\PaymentSucceededV1;
use Shopwise\Contracts\Events\Payments\RefundedV1;

/**
 * A Charge object as Stripe sends it in charge.refunded. `refunds` is listed only when a refund id is given (newer
 * API versions leave it out).
 *
 * @return array<string, mixed>
 */
function stripeCharge(Payment $payment, int $amountRefunded, ?string $refundId = null): array
{
    $charge = [
        'id' => 'ch_test_1',
        'object' => 'charge',
        'amount' => $payment->amount,
        'amount_refunded' => $amountRefunded,
        'payment_intent' => $payment->stripe_payment_intent_id,
        'refunded' => $amountRefunded >= $payment->amount,
    ];

    if ($refundId !== null) {
        $charge['refunds'] = ['object' => 'list', 'data' => [['id' => $refundId, 'object' => 'refund', 'amount' => $amountRefunded]], 'has_more' => false];
    }

    return $charge;
}

/**
 * Posts a raw body like Stripe does.
 */
function postWebhook(string $payload, ?string $signature): TestResponse
{
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($signature !== null) {
        $headers['HTTP_STRIPE_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/api/webhooks/stripe', server: $headers, content: $payload);
}

it('settles a processing payment on payment_intent.succeeded and publishes once', function (): void {
    fakeGateway();
    $payment = Payment::factory()->processing()->create();

    [$payload, $signature] = stripeEvent('payment_intent.succeeded', stripeIntent((string) $payment->stripe_payment_intent_id, 'succeeded'));

    postWebhook($payload, $signature)->assertOk();
    postWebhook($payload, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toHaveCount(1)
        ->and(DB::table('stripe_events')->count())->toBe(1);
});

it('fails a processing payment on payment_intent.payment_failed with the decline reason', function (): void {
    fakeGateway();
    $payment = Payment::factory()->processing()->create();

    [$payload, $signature] = stripeEvent('payment_intent.payment_failed', stripeIntent((string) $payment->stripe_payment_intent_id, 'requires_payment_method', [
        'type' => 'card_error',
        'code' => 'card_declined',
        'decline_code' => 'insufficient_funds',
        'message' => 'Your card has insufficient funds.',
    ]));

    postWebhook($payload, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe(FailureReason::InsufficientFunds)
        ->and($payment->failure_message)->toBe('Your card has insufficient funds.')
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toHaveCount(1);
});

it('does not publish again when the synchronous confirm already decided', function (): void {
    fakeGateway();
    $payment = Payment::factory()->succeeded()->create();

    [$payload, $signature] = stripeEvent('payment_intent.succeeded', stripeIntent((string) $payment->stripe_payment_intent_id, 'succeeded'));
    postWebhook($payload, $signature)->assertOk();

    expect(recordedEvents(PaymentSucceededV1::TYPE))->toBe([]);
});

it('fails a processing payment as canceled on payment_intent.canceled', function (): void {
    fakeGateway();
    $payment = Payment::factory()->processing()->create();

    [$payload, $signature] = stripeEvent('payment_intent.canceled', stripeIntent((string) $payment->stripe_payment_intent_id, 'canceled'));
    postWebhook($payload, $signature)->assertOk();

    expect($payment->refresh()->failure_reason)->toBe(FailureReason::Canceled);
});

it('publishes refunded with reason external for a partial refund made outside the saga', function (): void {
    $this->freezeTime();
    $gateway = fakeGateway();
    $payment = Payment::factory()->succeeded()->create(['amount' => 5220]);

    [$payload, $signature] = stripeEvent('charge.refunded', stripeCharge($payment, 2000, 're_dash_1'));
    postWebhook($payload, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refund_reason)->toBe('external')
        ->and($payment->refunded_amount)->toBe(2000)
        ->and($gateway->calls)->toBe([])
        ->and(recordedEvents(RefundedV1::TYPE))->toEqual([[
            'paymentId' => $payment->id,
            'checkoutId' => $payment->checkout_id,
            'customerId' => $payment->customer_id,
            'amount' => 2000,
            'currency' => 'USD',
            'reason' => 'external',
            'stripeRefundId' => 're_dash_1',
            'refundedAt' => now()->toIso8601ZuluString(),
        ]]);

    [$payload, $signature] = stripeEvent('charge.refunded', stripeCharge($payment, 5220), 'evt_test_2');
    postWebhook($payload, $signature)->assertOk();

    $events = recordedEvents(RefundedV1::TYPE);
    expect($payment->refresh()->refunded_amount)->toBe(5220)
        ->and($events)->toHaveCount(2)
        ->and($events[1]['amount'])->toBe(3220)
        ->and($events[1]['stripeRefundId'])->toStartWith('re_fake_external_')
        ->and($gateway->methodsCalled())->toBe(['latestRefundId']);
});

it('does not treat a refund the saga made as external', function (): void {
    $payment = Payment::factory()->refunded()->create(['amount' => 5220, 'refunded_amount' => 5220]);

    [$payload, $signature] = stripeEvent('charge.refunded', stripeCharge($payment, 5220, 're_saga'));
    postWebhook($payload, $signature)->assertOk();

    expect($payment->refresh()->refund_reason)->toBe('order_rejected')
        ->and(recordedEvents(RefundedV1::TYPE))->toBe([]);
});

it('finishes a 3-D Secure payment on payment_intent.succeeded', function (): void {
    fakeGateway();
    $payment = Payment::factory()->requiresAction()->create();

    [$payload, $signature] = stripeEvent('payment_intent.succeeded', stripeIntent((string) $payment->stripe_payment_intent_id, 'succeeded'));
    postWebhook($payload, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->client_secret)->toBeNull()
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toHaveCount(1);
});

it('fails a 3-D Secure payment the customer could not authenticate with authentication_required', function (): void {
    fakeGateway();
    $payment = Payment::factory()->requiresAction()->create();

    [$payload, $signature] = stripeEvent('payment_intent.payment_failed', stripeIntent((string) $payment->stripe_payment_intent_id, 'requires_payment_method', [
        'type' => 'invalid_request_error',
        'code' => 'payment_intent_authentication_failure',
        'message' => 'We are unable to authenticate your payment method.',
    ]));
    postWebhook($payload, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe(FailureReason::AuthenticationRequired)
        ->and(recordedEvents(PaymentFailedV1::TYPE)[0])->toMatchArray([
            'reason' => 'authentication_required',
            'message' => 'We are unable to authenticate your payment method.',
        ]);
});

it('finds a payment whose charge answer was lost by the checkout in the intent metadata', function (): void {
    fakeGateway();
    $payment = Payment::factory()->create();

    [$payload, $signature] = stripeEvent('payment_intent.succeeded', [
        ...stripeIntent('pi_lost_1', 'succeeded'),
        'metadata' => ['checkoutId' => $payment->checkout_id],
    ]);
    postWebhook($payload, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->stripe_payment_intent_id)->toBe('pi_lost_1');
});

it('acknowledges event types it does not handle and intents it does not know', function (): void {
    [$payload, $signature] = stripeEvent('customer.created', ['id' => 'cus_1', 'object' => 'customer'], 'evt_other');
    postWebhook($payload, $signature)->assertOk();

    [$payload, $signature] = stripeEvent('payment_intent.succeeded', stripeIntent('pi_unknown', 'succeeded'), 'evt_unknown');
    postWebhook($payload, $signature)->assertOk();

    expect(recordedEvents(PaymentSucceededV1::TYPE))->toBe([]);
});

it('rejects a bad, stale or missing signature and an unparsable body with 400', function (): void {
    $payment = Payment::factory()->processing()->create();
    [$payload, $signature] = stripeEvent('payment_intent.succeeded', stripeIntent((string) $payment->stripe_payment_intent_id, 'succeeded'));
    [, $staleSignature] = stripeEvent('payment_intent.succeeded', stripeIntent((string) $payment->stripe_payment_intent_id, 'succeeded'), timestamp: time() - 3600);

    postWebhook($payload, null)->assertBadRequest();
    postWebhook($payload, 't='.time().',v1=deadbeef')->assertBadRequest();
    postWebhook($payload.' ', $signature)->assertBadRequest();
    postWebhook($payload, $staleSignature)->assertBadRequest();

    $timestamp = time();
    $garbage = 'not json';
    postWebhook($garbage, "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$garbage}", 'whsec_test_secret'))->assertBadRequest();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Processing)
        ->and(DB::table('stripe_events')->count())->toBe(0);
});

it('rejects every webhook when no webhook secret is configured', function (): void {
    [$payload, $signature] = stripeEvent('payment_intent.succeeded', stripeIntent('pi_1', 'succeeded'));
    config(['services.stripe.webhook_secret' => null]);

    postWebhook($payload, $signature)->assertBadRequest();
});
