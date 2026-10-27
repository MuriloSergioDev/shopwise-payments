<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            // 3-D Secure (ADR-0004): the PaymentIntent's client secret, shown to checkout only through
            // payments.payment-requires-action, and when the intent is canceled if the customer does not finish.
            $table->string('client_secret')->nullable();
            $table->timestamp('action_required_at')->nullable();
            $table->timestamp('action_expires_at')->nullable()->index();
            // Minor units refunded so far: refunds made outside the saga may be partial.
            $table->unsignedBigInteger('refunded_amount')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['action_expires_at']);
            $table->dropColumn(['client_secret', 'action_required_at', 'action_expires_at', 'refunded_amount']);
        });
    }
};
