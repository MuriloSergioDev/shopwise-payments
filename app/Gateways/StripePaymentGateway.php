<?php

declare(strict_types=1);

namespace App\Gateways;

use App\Data\ChargeData;
use App\Data\IntentResult;
use App\Enums\FailureReason;
use App\Enums\IntentStatus;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Stripe (test mode) through stripe-php. Keys come from env only (`STRIPE_SECRET`).
 */
final readonly class StripePaymentGateway implements PaymentGateway
{
    public function __construct(private StripeClient $stripe) {}

    /**
     * Maps a PaymentIntent to what settling a payment needs. Also used for webhook payloads.
     */
    public static function toResult(PaymentIntent $intent): IntentResult
    {
        $error = $intent->last_payment_error;

        return match ($intent->status) {
            PaymentIntent::STATUS_SUCCEEDED => new IntentResult($intent->id, IntentStatus::Succeeded),
            PaymentIntent::STATUS_CANCELED => new IntentResult($intent->id, IntentStatus::Canceled),
            PaymentIntent::STATUS_REQUIRES_ACTION => new IntentResult($intent->id, IntentStatus::RequiresAction, clientSecret: $intent->client_secret, createdAt: $intent->created),
            // After a failed attempt (a decline, or failed 3-D Secure) the intent goes back to requires_payment_method
            // with the error on it.
            PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD => $error !== null
                ? new IntentResult($intent->id, IntentStatus::Failed, FailureReason::fromStripe($error->code ?? null, $error->decline_code ?? null), self::message($error->message ?? null))
                : new IntentResult($intent->id, IntentStatus::Pending),
            default => new IntentResult($intent->id, IntentStatus::Pending),
        };
    }

    public function charge(ChargeData $charge): IntentResult
    {
        try {
            $intent = $this->stripe->paymentIntents->create([
                'amount' => $charge->amount,
                'currency' => mb_strtolower($charge->currency),
                'payment_method' => $charge->paymentMethodId,
                'confirm' => true,
                // Synchronous confirm; 3-D Secure comes back as requires_action and the storefront finishes it with
                // stripe.handleNextAction (ADR-0004). No redirect-based methods.
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
                'metadata' => $charge->metadata,
            ], ['idempotency_key' => $charge->idempotencyKey]);
        } catch (CardException $e) {
            // A decline answers 402; the intent stays in requires_payment_method with the error on it.
            $intent = $e->getError()?->payment_intent;
            if ($intent instanceof PaymentIntent) {
                return self::toResult($intent);
            }

            throw $e;
        } catch (ApiConnectionException $e) {
            throw new ChargeOutcomeUnknown($e->getMessage(), $e->getCode(), previous: $e);
        } catch (ApiErrorException $e) {
            // A 5xx may come after Stripe created and confirmed the intent; a 4xx means nothing happened.
            if ($e->getHttpStatus() === null || $e->getHttpStatus() >= 500) {
                throw new ChargeOutcomeUnknown($e->getMessage(), $e->getCode(), previous: $e);
            }

            throw $e;
        }

        return self::toResult($intent);
    }

    public function find(ChargeData $charge): ?IntentResult
    {
        $checkoutId = $charge->metadata['checkoutId'] ?? null;
        if ($checkoutId === null) {
            return null;
        }

        // Search is eventually consistent (about a minute); the deadline sweeper runs later than that.
        $found = $this->stripe->paymentIntents->search([
            'query' => sprintf("metadata['checkoutId']:'%s'", str_replace("'", '', $checkoutId)),
            'limit' => 1,
        ])->first();

        return $found instanceof PaymentIntent ? self::toResult($found) : null;
    }

    public function retrieve(string $intentId): IntentResult
    {
        return self::toResult($this->stripe->paymentIntents->retrieve($intentId));
    }

    public function cancel(string $intentId): IntentResult
    {
        try {
            return self::toResult($this->stripe->paymentIntents->cancel($intentId));
        } catch (InvalidRequestException $e) {
            // Already succeeded or canceled: report how it ended instead.
            if ($e->getStripeCode() === 'payment_intent_unexpected_state') {
                return $this->retrieve($intentId);
            }

            throw $e;
        }
    }

    public function refund(string $intentId, string $idempotencyKey): string
    {
        return $this->stripe->refunds->create(['payment_intent' => $intentId], ['idempotency_key' => $idempotencyKey])->id;
    }

    public function latestRefundId(string $intentId): ?string
    {
        return $this->stripe->refunds->all(['payment_intent' => $intentId, 'limit' => 1])->first()?->id;
    }

    private static function message(?string $message): ?string
    {
        return $message === null ? null : mb_substr($message, 0, 500);
    }
}
