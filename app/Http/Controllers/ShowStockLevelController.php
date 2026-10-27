<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Resources\StockLevelDetailResource;
use App\Models\ReservationLine;
use App\Models\StockLevel;
use Illuminate\Database\Eloquent\Builder;

final class ShowStockLevelController
{
    public function __invoke(string $sku): StockLevelDetailResource
    {
        $level = StockLevel::query()->findOrFail($sku);

        $heldLines = ReservationLine::query()
            ->where('sku', $level->sku)
            ->whereHas('reservation', fn (Builder $query): Builder => $query->where('status', ReservationStatus::Held))
            ->with('reservation')
            ->get();

        return new StockLevelDetailResource($level, $heldLines);
    }
}
