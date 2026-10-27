<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\Request;
use Shopwise\Kit\Auth\JwtUser;

final class ShowCheckoutPaymentController
{
    /**
     * The payment of one of the caller's checkouts; someone else's is a 404, like a missing one.
     */
    public function __invoke(Request $request, string $checkoutId): PaymentResource
    {
        /** @var JwtUser $user */
        $user = $request->user();

        $payment = Payment::query()
            ->where('checkout_id', $checkoutId)
            ->where('customer_id', $user->id)
            ->firstOrFail();

        return new PaymentResource($payment);
    }
}
