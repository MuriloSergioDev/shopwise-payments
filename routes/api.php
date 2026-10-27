<?php

declare(strict_types=1);

use App\Http\Controllers\CompleteFakePaymentActionController;
use App\Http\Controllers\HandleStripeWebhookController;
use App\Http\Controllers\ListPaymentsController;
use App\Http\Controllers\ShowCheckoutPaymentController;
use Illuminate\Support\Facades\Route;

// Contract: shopwise-contracts/openapi/payments.yaml. The webhook is called by Stripe (locally through
// `stripe listen --forward-to`) and trusted only after its signature checks out; the rest by the BFFs with a JWT.
Route::post('/webhooks/stripe', HandleStripeWebhookController::class)->name('webhooks.stripe');

// Dev only (404 unless PAYMENTS_GATEWAY=fake): finishes a fake 3-D Secure action. Not in the contract.
Route::post('/dev/payment-actions', CompleteFakePaymentActionController::class)->name('dev.payment-actions');

Route::middleware('auth.jwt')->group(function (): void {
    Route::get('/checkouts/{checkoutId}/payment', ShowCheckoutPaymentController::class)->name('checkouts.payment');
});

Route::middleware('auth.jwt:admin')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/payments', ListPaymentsController::class)->name('payments.index');
});
