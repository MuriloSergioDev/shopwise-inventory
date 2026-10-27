<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Enums\ReleaseReason;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use LogicException;

/**
 * Gives a held or committed reservation's stock back and replies inventory.reservation-released with its lines.
 * Held stock leaves `reserved`; committed stock goes back onto `on_hand`.
 */
final readonly class ReservationReleaser
{
    public function __construct(
        private StockLedger $ledger,
        private ReservationReplies $replies,
    ) {}

    /**
     * @param  Reservation  $reservation  Read with lockForUpdate() in the caller's transaction.
     */
    public function release(Reservation $reservation, ReleaseReason $reason): void
    {
        $wasCommitted = match ($reservation->status) {
            ReservationStatus::Held => false,
            ReservationStatus::Committed => true,
            default => throw new LogicException("Reservation {$reservation->id} has nothing to release."),
        };

        $quantities = $reservation->quantitiesBySku();
        $levels = $this->ledger->lock(array_keys($quantities));
        foreach ($quantities as $sku => $quantity) {
            if ($wasCommitted) {
                $this->ledger->adjust($levels[$sku], onHandDelta: $quantity);
            } else {
                $this->ledger->adjust($levels[$sku], reservedDelta: -$quantity);
            }
        }

        $reservation->fill(['status' => ReservationStatus::Released, 'release_reason' => $reason, 'released_at' => now()])->save();

        $this->replies->released($reservation->id, $reservation->checkout_id, $reason, $reservation->eventLines(), now());
    }
}
