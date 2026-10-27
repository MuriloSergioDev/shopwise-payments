<?php

declare(strict_types=1);

namespace App\Gateways;

use App\Data\WebhookEvent;
use Stripe\Charge;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Verifies a Stripe webhook against the raw body with `STRIPE_WEBHOOK_SECRET` (the `whsec_...` that
 * `stripe listen` prints) and reduces the event to what we handle. Works the same with the fake gateway.
 */
final readonly class StripeWebhooks
{
    public function __construct(private ?string $secret, private int $tolerance = Webhook::DEFAULT_TOLERANCE) {}

    /**
     * @throws InvalidWebhook
     */
    public function parse(string $payload, string $signature): WebhookEvent
    {
        if ($this->secret === null || $this->secret === '') {
            throw new InvalidWebhook('STRIPE_WEBHOOK_SECRET is not set.');
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $this->secret, $this->tolerance);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new InvalidWebhook($e->getMessage(), $e->getCode(), previous: $e);
        }

        $object = $event->data->object ?? null;

        return match (true) {
            $object instanceof PaymentIntent => new WebhookEvent(
                $event->id,
                $event->type,
                $object->id,
                StripePaymentGateway::toResult($object),
                checkoutId: $this->checkoutId($object),
            ),
            $object instanceof Charge => new WebhookEvent(
                $event->id,
                $event->type,
                is_string($object->payment_intent) ? $object->payment_intent : $object->payment_intent?->id,
                amountRefunded: $object->amount_refunded,
                // Only listed when the account's API version still includes Charge.refunds.
                refundId: $object->refunds?->first()?->id,
            ),
            default => new WebhookEvent($event->id, $event->type),
        };
    }

    /**
     * `metadata.checkoutId` set by StripePaymentGateway::charge(); intents created elsewhere may have no metadata.
     */
    private function checkoutId(PaymentIntent $intent): ?string
    {
        $metadata = $intent->toArray()['metadata'] ?? null;

        return is_array($metadata) && is_string($metadata['checkoutId'] ?? null) ? $metadata['checkoutId'] : null;
    }
}
