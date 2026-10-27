<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CompleteFakePaymentAction;
use App\Http\Requests\CompleteFakePaymentActionRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;

final class CompleteFakePaymentActionController
{
    /**
     * Dev only, not in the OpenAPI contract: finishes a fake 3-D Secure action the way the storefront's
     * stripe.handleNextAction plus Stripe's webhook would. Knowing the client secret is the authorization, as with
     * Stripe. 404 unless the fake gateway is active, or for an unknown secret.
     */
    public function __invoke(CompleteFakePaymentActionRequest $request, CompleteFakePaymentAction $completeAction): PaymentResource
    {
        abort_unless(CompleteFakePaymentAction::isEnabled(), 404);

        $payment = Payment::query()->where('stripe_payment_intent_id', $request->intentId())->firstOrFail();

        return new PaymentResource($completeAction->handle($payment, $request->succeeded()));
    }
}
