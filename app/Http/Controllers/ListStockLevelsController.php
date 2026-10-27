<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListStockLevelsRequest;
use App\Http\Resources\StockLevelPageResource;
use App\Models\StockLevel;

final class ListStockLevelsController
{
    public function __invoke(ListStockLevelsRequest $request): StockLevelPageResource
    {
        $filters = $request->toData();

        $levels = StockLevel::query()
            ->when($filters->skuPrefix !== null, fn ($query) => $query->whereLike('sku', addcslashes((string) $filters->skuPrefix, '%_\\').'%', caseSensitive: false))
            ->when($filters->lowStock !== null, fn ($query) => $query->whereRaw('on_hand - reserved <= ?', [$filters->lowStock]))
            ->orderBy('sku')
            ->paginate($filters->perPage, ['*'], 'page', $filters->page);

        return new StockLevelPageResource($levels);
    }
}
