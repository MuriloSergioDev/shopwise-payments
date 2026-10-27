<?php

declare(strict_types=1);

use App\Enums\FailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Shopwise\Contracts\Events\Payments\PaymentFailedV1;
use Shopwise\Contracts\Events\Payments\PaymentSucceededV1;

it('finishes a fake 3-D Secure payment from its client secret', function (string $outcome, PaymentStatus $status): void {
    fakeGateway();
    $payment = Payment::factory()->requiresAction()->create();

    $this->postJson('/api/dev/payment-actions', ['clientSecret' => $payment->client_secret, 'outcome' => $outcome])
        ->assertOk()
        ->assertJsonPath('data.id', $payment->id)
        ->assertJsonPath('data.status', $status->value);

    expect($payment->refresh()->status)->toBe($status);
})->with([
    'succeeded' => ['succeeded', PaymentStatus::Succeeded],
    'failed' => ['failed', PaymentStatus::Failed],
]);

it('publishes the outcome once, like the webhook', function (): void {
    fakeGateway();
    $payment = Payment::factory()->requiresAction()->create();
    $secret = $payment->client_secret;

    $this->postJson('/api/dev/payment-actions', ['clientSecret' => $secret, 'outcome' => 'failed'])->assertOk();
    $this->postJson('/api/dev/payment-actions', ['clientSecret' => $secret, 'outcome' => 'succeeded'])
        ->assertOk()
        ->assertJsonPath('data.status', 'failed');

    expect($payment->refresh()->failure_reason)->toBe(FailureReason::AuthenticationRequired)
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(PaymentFailedV1::TYPE)[0]['reason'])->toBe('authentication_required')
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toBe([]);
});

it('is a 404 for an unknown secret and when the Stripe gateway is active', function (): void {
    $payment = Payment::factory()->requiresAction()->create();

    $this->postJson('/api/dev/payment-actions', ['clientSecret' => 'pi_unknown_secret_x', 'outcome' => 'succeeded'])->assertNotFound();

    config(['payments.gateway' => 'stripe']);
    $this->postJson('/api/dev/payment-actions', ['clientSecret' => $payment->client_secret, 'outcome' => 'succeeded'])->assertNotFound();

    expect($payment->refresh()->status)->toBe(PaymentStatus::RequiresAction);
});

it('validates the body', function (): void {
    $this->postJson('/api/dev/payment-actions', ['outcome' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['clientSecret', 'outcome']);
});

it('finishes a fake 3-D Secure payment from the command line', function (): void {
    fakeGateway();
    $payment = Payment::factory()->requiresAction()->create();

    $this->artisan('payments:fake-complete-action', ['checkoutId' => $payment->checkout_id, '--fail' => true])
        ->expectsOutputToContain('failed')
        ->assertSuccessful();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed);
});

it('refuses the command without a payment or without the fake gateway', function (): void {
    $this->artisan('payments:fake-complete-action', ['checkoutId' => CHECKOUT_ID])->assertFailed();

    config(['payments.gateway' => 'stripe']);
    $this->artisan('payments:fake-complete-action', ['checkoutId' => CHECKOUT_ID])->assertFailed();
});
