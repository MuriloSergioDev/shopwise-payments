<?php

declare(strict_types=1);

namespace App\Gateways;

use App\Data\ChargeData;
use App\Data\IntentResult;
use App\Enums\FailureReason;
use App\Enums\IntentStatus;

/**
 * Stripe stand-in for tests and for running the saga without Stripe keys. Outcomes follow Stripe's test payment
 * methods, so the same ids force a failure against real Stripe:
 *
 * - `pm_card_chargeDeclined` → declined (`card_declined`)
 * - `pm_card_chargeDeclinedInsufficientFunds` → declined (`insufficient_funds`)
 * - `pm_card_threeDSecure2Required`, `pm_card_authenticationRequired` → `requires_action` (3-D Secure) with a fake
 *   client secret; finish it with `POST /api/dev/payment-actions` or `payments:fake-complete-action`
 * - `pm_fake_processing` (fake only) → not final; the deadline or a webhook decides
 * - `pm_fake_lostResponse` (fake only) → charge() throws ChargeOutcomeUnknown, but the money was taken: find()
 *   returns a succeeded intent
 * - anything else (`pm_card_visa`) → succeeded
 *
 * Stateless across processes: the outcome is encoded in the intent id (`pi_fake_<outcome>_<hash>`).
 * `$calls` records what was asked, for tests.
 */
final class FakePaymentGateway implements PaymentGateway
{
    private const array OUTCOMES = [
        'pm_card_chargeDeclined' => 'declined',
        'pm_card_chargeDeclinedInsufficientFunds' => 'nofunds',
        'pm_card_threeDSecure2Required' => 'action',
        'pm_card_authenticationRequired' => 'action',
        'pm_fake_processing' => 'pending',
    ];

    private const string LOST_RESPONSE = 'pm_fake_lostResponse';

    /** @var list<array{method: string, intentId?: string, idempotencyKey?: string, charge?: ChargeData}> */
    public array $calls = [];

    public function charge(ChargeData $charge): IntentResult
    {
        $this->calls[] = ['method' => 'charge', 'idempotencyKey' => $charge->idempotencyKey, 'charge' => $charge];

        if ($charge->paymentMethodId === self::LOST_RESPONSE) {
            throw new ChargeOutcomeUnknown('Fake gateway: the response to creating the PaymentIntent was lost.');
        }

        return $this->result($this->intentId(self::OUTCOMES[$charge->paymentMethodId] ?? 'succeeded', $charge));
    }

    public function find(ChargeData $charge): ?IntentResult
    {
        $this->calls[] = ['method' => 'find', 'idempotencyKey' => $charge->idempotencyKey, 'charge' => $charge];

        return $charge->paymentMethodId === self::LOST_RESPONSE ? $this->result($this->intentId('succeeded', $charge)) : null;
    }

    public function retrieve(string $intentId): IntentResult
    {
        $this->calls[] = ['method' => 'retrieve', 'intentId' => $intentId];

        return $this->result($intentId);
    }

    public function cancel(string $intentId): IntentResult
    {
        $this->calls[] = ['method' => 'cancel', 'intentId' => $intentId];
        $current = $this->result($intentId);

        return $current->status === IntentStatus::Succeeded ? $current : new IntentResult($intentId, IntentStatus::Canceled);
    }

    public function refund(string $intentId, string $idempotencyKey): string
    {
        $this->calls[] = ['method' => 'refund', 'intentId' => $intentId, 'idempotencyKey' => $idempotencyKey];

        return 're_fake_'.mb_substr(hash('sha256', $idempotencyKey), 0, 16);
    }

    public function latestRefundId(string $intentId): string
    {
        $this->calls[] = ['method' => 'latestRefundId', 'intentId' => $intentId];

        return 're_fake_external_'.mb_substr(hash('sha256', $intentId), 0, 16);
    }

    /**
     * @return list<string>
     */
    public function methodsCalled(): array
    {
        return array_column($this->calls, 'method');
    }

    private function intentId(string $outcome, ChargeData $charge): string
    {
        return 'pi_fake_'.$outcome.'_'.mb_substr(hash('sha256', $charge->idempotencyKey), 0, 16);
    }

    private function result(string $intentId): IntentResult
    {
        $outcome = explode('_', $intentId)[2] ?? 'succeeded';

        return match ($outcome) {
            'declined', 'failed' => new IntentResult($intentId, IntentStatus::Failed, FailureReason::CardDeclined, 'Your card was declined.'),
            'nofunds' => new IntentResult($intentId, IntentStatus::Failed, FailureReason::InsufficientFunds, 'Your card has insufficient funds.'),
            'action' => new IntentResult($intentId, IntentStatus::RequiresAction, clientSecret: $intentId.'_secret_fake', createdAt: now()->getTimestamp()),
            'pending' => new IntentResult($intentId, IntentStatus::Pending),
            'canceled' => new IntentResult($intentId, IntentStatus::Canceled),
            default => new IntentResult($intentId, IntentStatus::Succeeded),
        };
    }
}
