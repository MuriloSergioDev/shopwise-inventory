<?php

declare(strict_types=1);

namespace App\Enums;

/** `reason` of inventory.reservation-failed. */
enum ReservationFailure: string
{
    case InsufficientStock = 'insufficient_stock';

    /** Not announced by catalog, or deactivated there. */
    case UnknownSku = 'unknown_sku';
}
