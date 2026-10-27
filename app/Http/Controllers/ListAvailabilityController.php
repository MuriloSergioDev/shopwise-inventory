<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListAvailabilityRequest;
use App\Http\Resources\AvailabilityResource;
use App\Models\StockLevel;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListAvailabilityController
{
    /**
     * Unknown SKUs are left out of the response.
     */
    public function __invoke(ListAvailabilityRequest $request): AnonymousResourceCollection
    {
        return AvailabilityResource::collection(StockLevel::query()->whereIn('sku', $request->skus())->orderBy('sku')->get());
    }
}
