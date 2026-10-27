<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SetStockLevel;
use App\Http\Requests\SetStockLevelRequest;
use App\Http\Resources\StockLevelResource;
use Illuminate\Http\JsonResponse;

final class SetStockLevelController
{
    /**
     * Always 200 (the contract has no 201).
     */
    public function __invoke(SetStockLevelRequest $request, string $sku, SetStockLevel $setStockLevel): JsonResponse
    {
        return StockLevelResource::make($setStockLevel->handle($sku, $request->onHand()))->response()->setStatusCode(200);
    }
}
