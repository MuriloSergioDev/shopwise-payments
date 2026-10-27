<?php

declare(strict_types=1);

use App\Messaging\Handlers\RefundPaymentHandler;
use App\Messaging\Handlers\RequestPaymentHandler;

return [
    'source' => env('SERVICE_NAME', 'payments'),

    'consumer_group' => env('KAFKA_CONSUMER_GROUP_ID', env('SERVICE_NAME', 'payments')),

    // Event type => handler. Every type listed here needs its topic in deploy/values.yaml → consumers.
    // These two are commands from checkout (ADR-0004); payments owns their topics.
    'handlers' => [
        'payments.request-payment' => RequestPaymentHandler::class,
        'payments.refund-payment' => RefundPaymentHandler::class,
    ],

    'max_attempts' => 3,

    'relay_batch_size' => 100,
];
