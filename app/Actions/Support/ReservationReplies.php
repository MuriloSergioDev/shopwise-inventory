<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Enums\ReleaseReason;
use App\Models\Reservation;
use Carbon\CarbonInterface;
use LogicException;
use Shopwise\Contracts\Events\Inventory\ReservationCommittedV1;
use Shopwise\Contracts\Events\Inventory\ReservationFailedV1;
use Shopwise\Contracts\Events\Inventory\ReservationReleasedV1;
use Shopwise\Contracts\Events\Inventory\StockReservedV1;
use Shopwise\Kit\Messaging\Outbox;

/**
 * Replies to checkout's commands (ADR-0004), recorded in the outbox and keyed by checkoutId. A repeated command
 * gets the same data again in a new envelope.
 */
final readonly class ReservationReplies
{
    public function reserved(Reservation $reservation): void
    {
        $event = new StockReservedV1(
            reservationId: $reservation->id,
            checkoutId: $reservation->checkout_id,
            lines: $reservation->eventLines(),
            expiresAt: $this->time($reservation->expires_at),
            reservedAt: $reservation->created_at->toIso8601ZuluString(),
        );

        Outbox::record(StockReservedV1::TYPE, $reservation->checkout_id, $event->toArray(), StockReservedV1::SCHEMA);
    }

    public function failed(Reservation $reservation): void
    {
        $event = new ReservationFailedV1(
            checkoutId: $reservation->checkout_id,
            reason: $reservation->failure_reason->value ?? throw new LogicException('A failed reservation has a failure reason.'),
            lines: $reservation->failed_lines ?? [],
            failedAt: $reservation->created_at->toIso8601ZuluString(),
        );

        Outbox::record(ReservationFailedV1::TYPE, $reservation->checkout_id, $event->toArray(), ReservationFailedV1::SCHEMA);
    }

    public function committed(Reservation $reservation): void
    {
        $event = new ReservationCommittedV1(
            reservationId: $reservation->id,
            checkoutId: $reservation->checkout_id,
            lines: $reservation->eventLines(),
            committedAt: $this->time($reservation->committed_at),
        );

        Outbox::record(ReservationCommittedV1::TYPE, $reservation->checkout_id, $event->toArray(), ReservationCommittedV1::SCHEMA);
    }

    /**
     * Built by hand: the generated ReservationReleasedV1 types reservationId as a string, but the schema allows null
     * (a checkout that never had a reservation).
     *
     * @param  list<array{sku: string, quantity: int}>  $lines  Stock returned by this release; empty when nothing was.
     */
    public function released(?string $reservationId, string $checkoutId, ReleaseReason $reason, array $lines, CarbonInterface $releasedAt): void
    {
        Outbox::record(ReservationReleasedV1::TYPE, $checkoutId, [
            'reservationId' => $reservationId,
            'checkoutId' => $checkoutId,
            'reason' => $reason->value,
            'lines' => $lines,
            'releasedAt' => $releasedAt->toIso8601ZuluString(),
        ], ReservationReleasedV1::SCHEMA);
    }

    private function time(?CarbonInterface $time): string
    {
        return $time?->toIso8601ZuluString() ?? throw new LogicException('Reservation time missing.');
    }
}
