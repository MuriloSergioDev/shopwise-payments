<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CompleteFakePaymentAction;
use App\Models\Payment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Fake gateway only: finish the 3-D Secure action of a checkout\'s payment (succeeds, or fails with --fail)')]
#[Signature('payments:fake-complete-action {checkoutId} {--fail : Fail it with authentication_required}')]
final class CompleteFakePaymentActionCommand extends Command
{
    public function handle(CompleteFakePaymentAction $completeAction): int
    {
        if (! CompleteFakePaymentAction::isEnabled()) {
            $this->error('Only with the fake gateway (PAYMENTS_GATEWAY=fake).');

            return self::FAILURE;
        }

        $payment = Payment::query()->where('checkout_id', $this->argument('checkoutId'))->first();
        if (! $payment instanceof Payment) {
            $this->error('No payment for this checkout.');

            return self::FAILURE;
        }

        $payment = $completeAction->handle($payment, ! $this->option('fail'));
        $this->info("Payment {$payment->id} is {$payment->status->value}.");

        return self::SUCCESS;
    }
}
