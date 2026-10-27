<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // One payment per checkout (ADR-0004).
            $table->string('checkout_id', 26)->unique();
            $table->string('customer_id', 26)->index();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('payment_method_id');
            $table->string('status', 20)->index();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('failure_reason', 40)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->string('stripe_refund_id')->nullable();
            $table->string('refund_reason', 40)->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stripe_events', function (Blueprint $table): void {
            // Stripe's event id (evt_...): webhook deliveries are deduped on it.
            $table->string('id')->primary();
            $table->string('type');
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('payments');
    }
};
