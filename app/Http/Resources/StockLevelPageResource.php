<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Response of GET /api/admin/stock: { data, meta } as in shopwise-contracts/openapi/inventory.yaml.
 *
 * @property LengthAwarePaginator<int, StockLevel> $resource
 */
final class StockLevelPageResource extends JsonResource
{
    /** The response already has its own top-level "data". */
    public static $wrap;

    /**
     * @return array{data: AnonymousResourceCollection, meta: array{page: int, perPage: int, total: int, lastPage: int}}
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => StockLevelResource::collection($this->resource->getCollection()),
            'meta' => [
                'page' => $this->resource->currentPage(),
                'perPage' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'lastPage' => $this->resource->lastPage(),
            ],
        ];
    }
}
