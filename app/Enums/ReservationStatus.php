<?php

declare(strict_types=1);

namespace App\Enums;

enum ReservationStatus: string
{
    /** Stock is held until expires_at; counted in stock_levels.reserved. */
    case Held = 'held';

    /** Sold: taken off on_hand, never expires. */
    case Committed = 'committed';

    /** Stock went back (requested by checkout or expired). */
    case Released = 'released';

    /** Nothing was held because a line could not be. */
    case Failed = 'failed';
}
