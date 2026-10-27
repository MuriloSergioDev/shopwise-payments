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

it('marks a payment refunded outside the saga on charge.refunded without an event', function (): void {
    fakeGateway();
    $payment = Payment::factory()->succeeded()->create();

    [$payload, $signature] = stripeEvent('charge.refunded', [
        'id' => 'ch_test_1',
        'object' => 'charge',
        'payment_intent' => $payment->stripe_payment_intent_id,
        'refunded' => true,
    ]);
    postWebhook($payload, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->refunded_at)->not->toBeNull()
        ->and(recordedEvents(RefundedV1::TYPE))->toBe([]);
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
