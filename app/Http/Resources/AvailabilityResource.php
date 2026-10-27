<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape defined in shopwise-contracts/openapi/inventory.yaml (components.schemas.Availability).
 *
 * @mixin StockLevel
 */
final class AvailabilityResource extends JsonResource
{
    /**
     * @return array{sku: string, available: int, inStock: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'sku' => $this->sku,
            'available' => $this->available(),
            'inStock' => $this->available() > 0,
        ];
    }
}
