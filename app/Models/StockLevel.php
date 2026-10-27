<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockLevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Shopwise\Contracts\Events\Inventory\StockLevelChangedV1;

/**
 * A SKU as catalog announced it, with its stock. `active` is read model state (catalog owns variants);
 * `on_hand` and `reserved` are inventory's own.
 *
 * @property string $sku
 * @property bool $active
 * @property Carbon $catalog_changed_at
 * @property int $on_hand
 * @property int $reserved
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['sku', 'active', 'catalog_changed_at', 'on_hand', 'reserved'])]
#[WithoutIncrementing]
final class StockLevel extends Model
{
    /** @use HasFactory<StockLevelFactory> */
    use HasFactory;

    protected $primaryKey = 'sku';

    protected $keyType = 'string';

    protected $attributes = ['on_hand' => 0, 'reserved' => 0];

    /** What can still be reserved. */
    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }

    public function changedEvent(): StockLevelChangedV1
    {
        return new StockLevelChangedV1(
            sku: $this->sku,
            onHand: $this->on_hand,
            reserved: $this->reserved,
            available: $this->available(),
            changedAt: $this->updated_at->toIso8601ZuluString(),
        );
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'catalog_changed_at' => 'datetime',
            'on_hand' => 'integer',
            'reserved' => 'integer',
        ];
    }
}
