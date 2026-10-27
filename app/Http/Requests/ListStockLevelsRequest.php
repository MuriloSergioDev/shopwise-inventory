<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\StockLevelFilters;
use Illuminate\Foundation\Http\FormRequest;

final class ListStockLevelsRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:64'],
            'lowStock' => ['nullable', 'integer', 'min:0'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function toData(): StockLevelFilters
    {
        $prefix = $this->string('q')->trim()->toString();

        return new StockLevelFilters(
            skuPrefix: $prefix === '' ? null : $prefix,
            lowStock: $this->filled('lowStock') ? $this->integer('lowStock') : null,
            page: $this->integer('page', 1),
            perPage: $this->integer('perPage', 25),
        );
    }
}
