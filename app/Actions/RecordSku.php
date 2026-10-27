<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\StockLevel;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Upserts a SKU from a catalog event. A new SKU starts with no stock; a state older than the stored one is ignored.
 * Stock is never touched here, so no stock-level-changed is recorded.
 */
final readonly class RecordSku
{
    public function handle(string $sku, bool $active, CarbonInterface $changedAt): void
    {
        DB::transaction(function () use ($sku, $active, $changedAt): void {
            $known = StockLevel::query()->lockForUpdate()->find($sku);
            if ($known instanceof StockLevel && $known->catalog_changed_at->greaterThan($changedAt)) {
                return;
            }

            $known ??= new StockLevel(['sku' => $sku]);
            $known->fill(['active' => $active, 'catalog_changed_at' => $changedAt]);
            $known->save();
        });
    }
}
