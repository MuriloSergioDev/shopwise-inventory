<?php

declare(strict_types=1);

namespace App\Enums;

/** `reason` of inventory.reservation-released. */
enum ReleaseReason: string
{
    case Requested = 'requested';
    case Expired = 'expired';
}
