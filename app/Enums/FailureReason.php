<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `reason` of payments.payment-failed and `failureReason` of the Payment resource.
 */
enum FailureReason: string
{
    case CardDeclined = 'card_declined';
    case InsufficientFunds = 'insufficient_funds';
    case AuthenticationRequired = 'authentication_required';
    case Canceled = 'canceled';
    case Timeout = 'timeout';
    case ProcessingError = 'processing_error';

    /**
     * Maps a Stripe error code / decline code (from `last_payment_error` or a CardException) to a reason.
     */
    public static function fromStripe(?string $code, ?string $declineCode): self
    {
        return match (true) {
            $declineCode === 'insufficient_funds' || $code === 'insufficient_funds' => self::InsufficientFunds,
            $declineCode === 'authentication_required' || $code === 'authentication_required' || $code === 'payment_intent_authentication_failure' => self::AuthenticationRequired,
            $code === 'card_declined', $code === 'expired_card', $code === 'incorrect_cvc', $code === 'incorrect_number', $code === 'invalid_cvc', $code === 'invalid_expiry_month', $code === 'invalid_expiry_year' => self::CardDeclined,
            default => self::ProcessingError,
        };
    }
}
