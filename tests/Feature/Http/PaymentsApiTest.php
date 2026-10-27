<?php

declare(strict_types=1);

use App\Enums\FailureReason;
use App\Models\Payment;

const CUSTOMER_ID = '01JBCDEFGHJKMNPQRSTVWXYZ11';

it('shows the payment of one of the caller\'s checkouts', function (): void {
    $payment = Payment::factory()->failed(FailureReason::InsufficientFunds)->create(['customer_id' => CUSTOMER_ID, 'amount' => 5220]);

    $this->getJson("/api/checkouts/{$payment->checkout_id}/payment", bearer(['customer']))
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $payment->id,
            'checkoutId' => $payment->checkout_id,
            'customerId' => CUSTOMER_ID,
            'status' => 'failed',
            'amount' => 5220,
            'currency' => 'USD',
            'failureReason' => 'insufficient_funds',
            'failureMessage' => 'Your card was declined.',
            'refundedAt' => null,
            'createdAt' => $payment->created_at->toIso8601ZuluString(),
            'updatedAt' => $payment->updated_at->toIso8601ZuluString(),
        ]]);
});

it('hides other customers\' payments and missing ones behind 404', function (): void {
    $payment = Payment::factory()->succeeded()->create(['customer_id' => '01JBCDEFGHJKMNPQRSTVWXYZ77']);

    $this->getJson("/api/checkouts/{$payment->checkout_id}/payment", bearer(['customer']))->assertNotFound();
    $this->getJson('/api/checkouts/01JBCDEFGHJKMNPQRSTVWXYZ00/payment', bearer(['customer']))->assertNotFound();
});

it('needs a token for a checkout payment', function (): void {
    $payment = Payment::factory()->create(['customer_id' => CUSTOMER_ID]);

    $this->getJson("/api/checkouts/{$payment->checkout_id}/payment")->assertUnauthorized();
});

it('lists payments newest first with page meta', function (): void {
    $payments = collect(range(1, 3))->map(fn (): Payment => Payment::factory()->succeeded()->create());

    $this->getJson('/api/admin/payments?perPage=2', bearer(['admin']))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$payments[2]->id, $payments[1]->id])
        ->assertJsonPath('meta', ['page' => 1, 'perPage' => 2, 'total' => 3, 'lastPage' => 2]);

    $this->getJson('/api/admin/payments?perPage=2&page=2', bearer(['admin']))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$payments[0]->id]);
});

it('filters payments by status', function (): void {
    Payment::factory()->succeeded()->create();
    $refunded = Payment::factory()->refunded()->create();

    $this->getJson('/api/admin/payments?status=refunded', bearer(['admin']))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$refunded->id])
        ->assertJsonPath('data.0.refundedAt', $refunded->refunded_at?->toIso8601ZuluString());
});

it('validates the payment list query', function (): void {
    $this->getJson('/api/admin/payments?status=bogus&perPage=500&page=0', bearer(['admin']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'perPage', 'page']);
});

it('needs an admin token to list payments', function (): void {
    $this->getJson('/api/admin/payments')->assertUnauthorized();
    $this->getJson('/api/admin/payments', bearer(['customer']))->assertForbidden();
});
