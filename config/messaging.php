<?php

declare(strict_types=1);

use App\Messaging\Handlers\CommitReservationHandler;
use App\Messaging\Handlers\ReleaseReservationHandler;
use App\Messaging\Handlers\ReserveStockHandler;
use App\Messaging\Handlers\VariantChangedHandler;

return [
    'source' => env('SERVICE_NAME', 'inventory'),

    'consumer_group' => env('KAFKA_CONSUMER_GROUP_ID', env('SERVICE_NAME', 'inventory')),

    // Message type => handler. Every type listed here needs its topic in deploy/values.yaml → consumers.
    'handlers' => [
        'catalog.variant-changed' => VariantChangedHandler::class,
        // Checkout's saga commands (ADR-0004).
        'inventory.reserve-stock' => ReserveStockHandler::class,
        'inventory.commit-reservation' => CommitReservationHandler::class,
        'inventory.release-reservation' => ReleaseReservationHandler::class,
    ],

    'max_attempts' => 3,

    'relay_batch_size' => 100,
];
