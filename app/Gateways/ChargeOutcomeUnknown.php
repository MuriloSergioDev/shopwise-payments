<?php

declare(strict_types=1);

namespace App\Gateways;

use RuntimeException;

/**
 * Creating or confirming the PaymentIntent failed in a way that does not say whether Stripe took the money (no
 * response, a timeout, a 5xx). The payment stays pending and is reconciled with PaymentGateway::find().
 */
final class ChargeOutcomeUnknown extends RuntimeException {}
