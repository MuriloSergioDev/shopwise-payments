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
 * - `pm_card_authenticationRequired` → `requires_action` (we cancel it)
 * - `pm_fake_processing` (fake only) → not final; the deadline or a webhook decides
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
        'pm_card_authenticationRequired' => 'action',
        'pm_fake_processing' => 'pending',
    ];

    /** @var list<array{method: string, intentId?: string, idempotencyKey?: string, charge?: ChargeData}> */
    public array $calls = [];

    public function charge(ChargeData $charge): IntentResult
    {
        $this->calls[] = ['method' => 'charge', 'idempotencyKey' => $charge->idempotencyKey, 'charge' => $charge];
        $outcome = self::OUTCOMES[$charge->paymentMethodId] ?? 'succeeded';

        return $this->result('pi_fake_'.$outcome.'_'.mb_substr(hash('sha256', $charge->idempotencyKey), 0, 16));
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

    /**
     * @return list<string>
     */
    public function methodsCalled(): array
    {
        return array_column($this->calls, 'method');
    }

    private function result(string $intentId): IntentResult
    {
        $outcome = explode('_', $intentId)[2] ?? 'succeeded';

        return match ($outcome) {
            'declined', 'failed' => new IntentResult($intentId, IntentStatus::Failed, FailureReason::CardDeclined, 'Your card was declined.'),
            'nofunds' => new IntentResult($intentId, IntentStatus::Failed, FailureReason::InsufficientFunds, 'Your card has insufficient funds.'),
            'action' => new IntentResult($intentId, IntentStatus::RequiresAction),
            'pending' => new IntentResult($intentId, IntentStatus::Pending),
            'canceled' => new IntentResult($intentId, IntentStatus::Canceled),
            default => new IntentResult($intentId, IntentStatus::Succeeded),
        };
    }
}
