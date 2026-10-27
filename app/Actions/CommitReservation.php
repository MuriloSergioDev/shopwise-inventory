<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Support\ReservationReleaser;
use App\Actions\Support\ReservationReplies;
use App\Actions\Support\StockLedger;
use App\Enums\ReleaseReason;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * inventory.commit-reservation: the held stock is sold (taken off on_hand) and no longer expires. A reservation that
 * already expired, or one that is unknown, is answered with reservation-released (reason expired), so checkout
 * refunds the payment.
 */
final readonly class CommitReservation
{
    public function __construct(
        private StockLedger $ledger,
        private ReservationReplies $replies,
        private ReservationReleaser $releaser,
    ) {}

    public function handle(string $checkoutId, string $reservationId): void
    {
        DB::transaction(function () use ($checkoutId, $reservationId): void {
            $reservation = Reservation::query()->whereKey($reservationId)->where('checkout_id', $checkoutId)->lockForUpdate()->first();

            if (! $reservation instanceof Reservation || $reservation->status === ReservationStatus::Failed) {
                $this->replies->released($reservationId, $checkoutId, ReleaseReason::Expired, [], now());

                return;
            }

            if ($reservation->isExpired()) {
                // The TTL job has not got to it yet: expire it now rather than selling stock past its deadline.
                $this->releaser->release($reservation, ReleaseReason::Expired);

                return;
            }

            match ($reservation->status) {
                ReservationStatus::Held => $this->commit($reservation),
                ReservationStatus::Committed => $this->replies->committed($reservation),
                ReservationStatus::Released => $this->replies->released(
                    $reservation->id,
                    $checkoutId,
                    $reservation->release_reason ?? ReleaseReason::Expired,
                    [],
                    $reservation->released_at ?? now(),
                ),
            };
        });
    }

    private function commit(Reservation $reservation): void
    {
        $quantities = $reservation->quantitiesBySku();
        $levels = $this->ledger->lock(array_keys($quantities));
        foreach ($quantities as $sku => $quantity) {
            $this->ledger->adjust($levels[$sku], onHandDelta: -$quantity, reservedDelta: -$quantity);
        }

        $reservation->fill(['status' => ReservationStatus::Committed, 'committed_at' => now()])->save();

        $this->replies->committed($reservation);
    }
}
