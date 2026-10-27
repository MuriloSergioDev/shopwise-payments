<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\HandleStripeEvent;
use App\Gateways\InvalidWebhook;
use App\Gateways\StripeWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class HandleStripeWebhookController
{
    /**
     * Checks the Stripe-Signature against the raw body before reading anything, then applies the event once.
     * 200 for handled, ignored and already-seen events; 400 for a bad signature or body.
     */
    public function __invoke(Request $request, StripeWebhooks $webhooks, HandleStripeEvent $handleEvent): JsonResponse
    {
        try {
            $event = $webhooks->parse($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (InvalidWebhook $e) {
            Log::warning('Stripe webhook rejected', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid Stripe webhook.'], 400);
        }

        $handleEvent->handle($event);

        return response()->json(['received' => true]);
    }
}
