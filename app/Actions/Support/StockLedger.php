<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Models\StockLevel;
use LogicException;
use RuntimeException;
use Shopwise\Contracts\Events\Inventory\StockLevelChangedV1;
use Shopwise\Kit\Messaging\Outbox;

/**
 * Every change to on_hand/reserved goes through here, inside the caller's transaction: rows are locked in SKU order
 * and written with a compare-and-set, and each change records inventory.stock-level-changed.
 */
final readonly class StockLedger
{
    /**
     * Locks the stock rows of these SKUs. Always in SKU order, so two reservations over overlapping SKUs wait for
     * each other instead of deadlocking. SKUs inventory does not know are missing from the result.
     *
     * @param  list<string>  $skus
     * @return array<string, StockLevel>
     */
    public function lock(array $skus): array
    {
        $levels = [];
        foreach (StockLevel::query()->whereIn('sku', $skus)->orderBy('sku')->lockForUpdate()->get() as $level) {
            $levels[$level->sku] = $level;
        }

        return $levels;
    }

    /**
     * Applies a change to a row read under lock(). The update is conditional on the row still holding the values
     * read, so even a caller that forgot to lock cannot oversell: it fails (and the consumer retries) instead.
     */
    public function adjust(StockLevel $level, int $onHandDelta = 0, int $reservedDelta = 0): void
    {
        $onHand = $level->on_hand + $onHandDelta;
        $reserved = $level->reserved + $reservedDelta;
        if ($reserved < 0 || $reserved > $onHand) {
            throw new LogicException("Stock of {$level->sku} would become on_hand={$onHand}, reserved={$reserved}.");
        }

        $now = now();
        $updated = StockLevel::query()
            ->whereKey($level->sku)
            ->where('on_hand', $level->on_hand)
            ->where('reserved', $level->reserved)
            ->update(['on_hand' => $onHand, 'reserved' => $reserved, 'updated_at' => $now]);
        if ($updated !== 1) {
            throw new RuntimeException("Stock of {$level->sku} changed concurrently.");
        }

        $level->forceFill(['on_hand' => $onHand, 'reserved' => $reserved, 'updated_at' => $now])->syncOriginal();

        Outbox::record(StockLevelChangedV1::TYPE, $level->sku, $level->changedEvent()->toArray(), StockLevelChangedV1::SCHEMA);
    }
}
