<?php

declare(strict_types=1);

use App\Enums\FailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Shopwise\Contracts\Events\Payments\PaymentFailedV1;
use Shopwise\Contracts\Events\Payments\PaymentSucceededV1;

it('cancels a payment still processing after five minutes and replies timeout', function (): void {
    $gateway = fakeGateway();
    $stale = Payment::factory()->processing()->create(['created_at' => now()->subMinutes(6)]);
    $fresh = Payment::factory()->processing()->create(['created_at' => now()->subMinutes(4)]);

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($stale->failure_reason)->toBe(FailureReason::Timeout)
        ->and($fresh->refresh()->status)->toBe(PaymentStatus::Processing)
        ->and($gateway->methodsCalled())->toBe(['retrieve', 'cancel'])
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(PaymentFailedV1::TYPE)[0]['reason'])->toBe('timeout');
});

it('settles a stale payment with the outcome Stripe reached', function (): void {
    $gateway = fakeGateway();
    $payment = Payment::factory()->processing('pi_fake_succeeded')->create(['created_at' => now()->subMinutes(6)]);

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($gateway->methodsCalled())->toBe(['retrieve'])
        ->and(recordedEvents(PaymentSucceededV1::TYPE))->toHaveCount(1);
});

it('leaves finished payments alone', function (): void {
    $gateway = fakeGateway();
    Payment::factory()->succeeded()->create(['created_at' => now()->subHour()]);
    Payment::factory()->failed()->create(['created_at' => now()->subHour()]);

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($gateway->calls)->toBe([])
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toBe([]);
});

it('gives a payment waiting for 3-D Secure ten minutes, then cancels it and replies timeout', function (): void {
    $gateway = fakeGateway();
    $expired = Payment::factory()->requiresAction(minutesAgo: 11)->create();
    $waiting = Payment::factory()->requiresAction(minutesAgo: 7)->create();

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($expired->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($expired->failure_reason)->toBe(FailureReason::Timeout)
        ->and($waiting->refresh()->status)->toBe(PaymentStatus::RequiresAction)
        ->and($gateway->methodsCalled())->toBe(['retrieve', 'cancel'])
        ->and(recordedEvents(PaymentFailedV1::TYPE))->toHaveCount(1)
        ->and(recordedEvents(PaymentFailedV1::TYPE)[0]['reason'])->toBe('timeout');
});

it('looks up a stale payment whose charge answer was lost and settles what Stripe did', function (): void {
    $gateway = fakeGateway();
    $charged = Payment::factory()->create(['payment_method_id' => 'pm_fake_lostResponse', 'created_at' => now()->subMinutes(6)]);
    $nothing = Payment::factory()->create(['created_at' => now()->subMinutes(6)]);

    $this->artisan('payments:expire-stale')->assertSuccessful();

    expect($charged->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($nothing->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($nothing->failure_reason)->toBe(FailureReason::Timeout)
        ->and($gateway->methodsCalled())->toBe(['find', 'find']);
});
