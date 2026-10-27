<?php

declare(strict_types=1);

use App\Models\StockLevel;

it('returns availability of the known SKUs, without a token', function (): void {
    StockLevel::factory()->stocked(5, reserved: 2)->create(['sku' => 'TEE-MER-BLA-M']);
    StockLevel::factory()->stocked(1, reserved: 1)->create(['sku' => 'SKL-CST']);

    $this->getJson('/api/stock?skus[]=TEE-MER-BLA-M&skus[]=SKL-CST&skus[]=UNKNOWN&skus[]=SKL-CST')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['sku' => 'SKL-CST', 'available' => 0, 'inStock' => false],
            ['sku' => 'TEE-MER-BLA-M', 'available' => 3, 'inStock' => true],
        ]]);
});

it('validates the SKU list', function (string $query): void {
    $this->getJson("/api/stock{$query}")->assertUnprocessable();
})->with([
    'missing' => [''],
    'too long' => ['?skus[]='.str_repeat('A', 65)],
    'too many' => ['?'.implode('&', array_map(fn (int $i): string => "skus[]=S{$i}", range(1, 101)))],
]);
