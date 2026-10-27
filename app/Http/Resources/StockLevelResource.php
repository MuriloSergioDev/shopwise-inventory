<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape defined in shopwise-contracts/openapi/inventory.yaml (components.schemas.StockLevel).
 *
 * @mixin StockLevel
 */
final class StockLevelResource extends JsonResource
{
    /**
     * @return array{sku: string, onHand: int, reserved: int, available: int, updatedAt: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'sku' => $this->sku,
            'onHand' => $this->on_hand,
            'reserved' => $this->reserved,
            'available' => $this->available(),
            'updatedAt' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
