<?php

declare(strict_types=1);

namespace App\Data;

final readonly class StockLevelFilters
{
    public function __construct(
        public ?string $skuPrefix,
        public ?int $lowStock,
        public int $page,
        public int $perPage,
    ) {}
}
