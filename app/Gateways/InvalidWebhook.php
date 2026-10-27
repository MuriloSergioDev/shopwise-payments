<?php

declare(strict_types=1);

namespace App\Gateways;

use RuntimeException;

/**
 * A webhook whose signature, timestamp or body does not check out (or no webhook secret is configured).
 */
final class InvalidWebhook extends RuntimeException {}
