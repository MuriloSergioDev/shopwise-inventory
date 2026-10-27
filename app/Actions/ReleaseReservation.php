<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Support\ReservationReleaser;
use App\Actions\Support\ReservationReplies;
use App\Enums\ReleaseReason;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * inventory.release-reservation (compensation): returns the checkout's held or committed stock. When there is
 * nothing to return (never reserved, failed, already released) it still replies, with empty lines.
 */
final readonly class ReleaseReservation
{
    public function __construct(
        private ReservationReplies $replies,
        private ReservationReleaser $releaser,
    ) {}

    public function handle(string $checkoutId): void
    {
        DB::transaction(function () use ($checkoutId): void {
            $reservation = Reservation::query()->where('checkout_id', $checkoutId)->lockForUpdate()->first();

            match ($reservation?->status) {
                ReservationStatus::Held, ReservationStatus::Committed => $this->releaser->release($reservation, ReleaseReason::Requested),
                ReservationStatus::Released => $this->replies->released($reservation->id, $checkoutId, ReleaseReason::Requested, [], now()),
                // Never had a reservation (failed, or the command never arrived): reservationId is null.
                default => $this->replies->released(null, $checkoutId, ReleaseReason::Requested, [], now()),
            };
        });
    }
}
