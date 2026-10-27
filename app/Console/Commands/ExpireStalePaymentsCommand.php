<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ExpirePayment;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Settle or cancel payments still processing after the deadline (payments.deadline_seconds)')]
#[Signature('payments:expire-stale')]
final class ExpireStalePaymentsCommand extends Command
{
    public function handle(ExpirePayment $expirePayment): int
    {
        $deadline = now()->subSeconds(config()->integer('payments.deadline_seconds'));

        Payment::query()
            ->where('status', PaymentStatus::Processing)
            ->where('created_at', '<=', $deadline)
            ->orderBy('id')
            ->get(['id'])
            ->each(fn (Payment $payment) => $expirePayment->handle($payment->id));

        return self::SUCCESS;
    }
}
