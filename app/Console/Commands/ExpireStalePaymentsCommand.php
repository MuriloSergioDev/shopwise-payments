<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ExpirePayment;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Description('Settle or cancel payments still pending after their deadline (payments.deadline_seconds, or the 3-D Secure action_expires_at)')]
#[Signature('payments:expire-stale')]
final class ExpireStalePaymentsCommand extends Command
{
    public function handle(ExpirePayment $expirePayment): int
    {
        $deadline = now()->subSeconds(config()->integer('payments.deadline_seconds'));

        Payment::query()
            ->where(fn (Builder $query) => $query->where('status', PaymentStatus::Processing)->where('created_at', '<=', $deadline))
            ->orWhere(fn (Builder $query) => $query->where('status', PaymentStatus::RequiresAction)->where('action_expires_at', '<=', now()))
            ->orderBy('id')
            ->get(['id'])
            ->each(fn (Payment $payment) => $expirePayment->handle($payment->id));

        return self::SUCCESS;
    }
}
