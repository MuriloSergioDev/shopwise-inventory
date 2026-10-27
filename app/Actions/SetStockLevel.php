<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Support\StockLedger;
use App\Models\StockLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets a SKU's on-hand count (a stock count, not a delta). 404 for a SKU catalog never announced, 422 below the
 * reserved quantity. Records inventory.stock-level-changed only when the count changed.
 */
final readonly class SetStockLevel
{
    public function __construct(private StockLedger $ledger) {}

    public function handle(string $sku, int $onHand): StockLevel
    {
        return DB::transaction(function () use ($sku, $onHand): StockLevel {
            $level = $this->ledger->lock([$sku])[$sku] ?? StockLevel::query()->findOrFail($sku);

            if ($onHand < $level->reserved) {
                throw ValidationException::withMessages([
                    'onHand' => "On hand cannot go below the {$level->reserved} units held by open reservations.",
                ]);
            }

            if ($onHand !== $level->on_hand) {
                $this->ledger->adjust($level, onHandDelta: $onHand - $level->on_hand);
            }

            return $level;
        });
    }
}
