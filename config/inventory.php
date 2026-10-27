<?php

declare(strict_types=1);

return [
    // How long a reservation holds stock before inventory:expire-reservations gives it back (ADR-0004: 15 minutes).
    'reservation_ttl_minutes' => (int) env('INVENTORY_RESERVATION_TTL_MINUTES', 15),
];
