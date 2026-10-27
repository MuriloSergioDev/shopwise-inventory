<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReservationLine;
use App\Models\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Response of GET /api/admin/stock/{sku}: the StockLevel plus the open (held) reservations of this SKU.
 *
 * @property StockLevel $resource
 */
final class StockLevelDetailResource extends JsonResource
{
    /**
     * @param  Collection<int, ReservationLine>  $heldLines  This SKU's lines of held reservations, with their reservation.
     */
    public function __construct(StockLevel $resource, private readonly Collection $heldLines)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reservations = $this->heldLines
            ->groupBy('reservation_id')
            ->map(function (Collection $lines): array {
                /** @var ReservationLine $first */
                $first = $lines->first();

                return [
                    'id' => $first->reservation->id,
                    'checkoutId' => $first->reservation->checkout_id,
                    'quantity' => $lines->sum(fn (ReservationLine $line): int => $line->quantity),
                    'status' => $first->reservation->status->value,
                    'expiresAt' => $first->reservation->expires_at?->toIso8601ZuluString(),
                ];
            })
            ->sortBy('expiresAt')
            ->values()
            ->all();

        return [...StockLevelResource::make($this->resource)->toArray($request), 'reservations' => $reservations];
    }
}
