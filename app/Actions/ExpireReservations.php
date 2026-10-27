<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Support\ReservationReleaser;
use App\Enums\ReleaseReason;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Releases held reservations whose TTL passed, one transaction each, replying reservation-released (reason expired).
 * Run every minute by the scheduler (`inventory:expire-reservations`).
 */
final readonly class ExpireReservations
{
    public function __construct(private ReservationReleaser $releaser) {}

    /**
     * @return int How many reservations expired.
     */
    public function handle(): int
    {
        $expired = 0;
        $ids = Reservation::query()
            ->where('status', ReservationStatus::Held)
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->pluck('id');

        foreach ($ids as $id) {
            $expired += DB::transaction(function () use ($id): int {
                // Re-read under lock: checkout may have committed or released it since the scan.
                $reservation = Reservation::query()->whereKey($id)->lockForUpdate()->first();
                if (! $reservation instanceof Reservation || ! $reservation->isExpired()) {
                    return 0;
                }

                $this->releaser->release($reservation, ReleaseReason::Expired);

                return 1;
            });
        }

        return $expired;
    }
}
