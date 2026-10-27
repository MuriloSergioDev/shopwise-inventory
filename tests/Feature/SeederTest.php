<?php

declare(strict_types=1);

use App\Models\StockLevel;
use Database\Seeders\DatabaseSeeder;
use Shopwise\Contracts\Events\Inventory\StockLevelChangedV1;

it('stocks every SKU of the demo catalog once, through the actions', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(StockLevel::query()->count())->toBe(28)
        ->and(StockLevel::query()->where('active', false)->count())->toBe(0)
        ->and(recordedEvents(StockLevelChangedV1::TYPE))->toHaveCount(28);

    $this->getJson('/api/stock?skus[]=TEE-MER-BLA-M&skus[]=SKL-CST&skus[]=COF-POUR-CHA')
        ->assertOk()
        ->assertJsonPath('data.*.available', [2, 3, 50]);
});

it('stocks SKUs that catalog events already created', function (): void {
    StockLevel::factory()->create(['sku' => 'TEE-MER-BLA-M', 'catalog_changed_at' => '2026-10-26T12:00:00Z']);

    $this->seed(DatabaseSeeder::class);

    $level = StockLevel::query()->findOrFail('TEE-MER-BLA-M');
    expect($level->on_hand)->toBe(50)
        ->and($level->catalog_changed_at->toIso8601ZuluString())->toBe('2026-10-26T12:00:00Z');
});
