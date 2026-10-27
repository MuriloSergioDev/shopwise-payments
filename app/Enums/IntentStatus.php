<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a PaymentIntent's Stripe status means for us.
 */
enum IntentStatus: string
{
    /** `succeeded`: the money is captured. */
    case Succeeded = 'succeeded';

    /** `requires_payment_method` after a failed attempt: declined. */
    case Failed = 'failed';

    /** `requires_action` (3-D Secure): the customer must authenticate; the webhook finishes it. */
    case RequiresAction = 'requires_action';

    /** `canceled`. */
    case Canceled = 'canceled';

    /** Anything else (`processing`, ...): not final yet; the webhook or the deadline decides. */
    case Pending = 'pending';
}
