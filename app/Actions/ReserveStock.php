<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Support\ReservationReplies;
use App\Actions\Support\StockLedger;
use App\Enums\ReleaseReason;
use App\Enums\ReservationFailure;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\StockLevel;
use Illuminate\Support\Facades\DB;

/**
 * inventory.reserve-stock: holds every line or nothing. One reservation per checkout; a repeated command does no
 * work and re-publishes the outcome (stock-reserved, reservation-failed, or reservation-released once it is gone).
 */
final readonly class ReserveStock
{
    public function __construct(
        private StockLedger $ledger,
        private ReservationReplies $replies,
    ) {}

    /**
     * @param  list<array{sku: string, quantity: int}>  $lines
     */
    public function handle(string $checkoutId, array $lines): Reservation
    {
        return DB::transaction(function () use ($checkoutId, $lines): Reservation {
            $existing = Reservation::query()->where('checkout_id', $checkoutId)->first();
            if ($existing instanceof Reservation) {
                $this->republish($existing);

                return $existing;
            }

            $requested = [];
            foreach ($lines as $line) {
                $requested[$line['sku']] = ($requested[$line['sku']] ?? 0) + $line['quantity'];
            }
            ksort($requested);

            $levels = $this->ledger->lock(array_keys($requested));
            $failure = $this->failure($requested, $levels);
            if ($failure !== null) {
                $reservation = Reservation::query()->create([
                    'checkout_id' => $checkoutId,
                    'status' => ReservationStatus::Failed,
                    'failure_reason' => $failure[0],
                    'failed_lines' => $failure[1],
                ]);
                $this->replies->failed($reservation);

                return $reservation;
            }

            $reservation = Reservation::query()->create([
                'checkout_id' => $checkoutId,
                'status' => ReservationStatus::Held,
                'expires_at' => now()->addMinutes(config()->integer('inventory.reservation_ttl_minutes')),
            ]);
            $reservation->lines()->createMany($lines);

            foreach ($requested as $sku => $quantity) {
                $this->ledger->adjust($levels[$sku], reservedDelta: $quantity);
            }

            $this->replies->reserved($reservation);

            return $reservation;
        });
    }

    /**
     * Unknown (or deactivated) SKUs fail the reservation before stock is even looked at.
     *
     * @param  array<string, int>  $requested
     * @param  array<string, StockLevel>  $levels
     * @return array{ReservationFailure, list<array{sku: string, requested: int, available: int}>}|null
     */
    private function failure(array $requested, array $levels): ?array
    {
        $unknown = [];
        $short = [];
        foreach ($requested as $sku => $quantity) {
            $level = $levels[$sku] ?? null;
            if (! $level instanceof StockLevel || ! $level->active) {
                $unknown[] = ['sku' => $sku, 'requested' => $quantity, 'available' => 0];
            } elseif ($level->available() < $quantity) {
                $short[] = ['sku' => $sku, 'requested' => $quantity, 'available' => $level->available()];
            }
        }

        return match (true) {
            $unknown !== [] => [ReservationFailure::UnknownSku, $unknown],
            $short !== [] => [ReservationFailure::InsufficientStock, $short],
            default => null,
        };
    }

    private function republish(Reservation $reservation): void
    {
        match ($reservation->status) {
            ReservationStatus::Held, ReservationStatus::Committed => $this->replies->reserved($reservation),
            ReservationStatus::Failed => $this->replies->failed($reservation),
            ReservationStatus::Released => $this->replies->released(
                $reservation->id,
                $reservation->checkout_id,
                $reservation->release_reason ?? ReleaseReason::Requested,
                [],
                $reservation->released_at ?? now(),
            ),
        };
    }
}
