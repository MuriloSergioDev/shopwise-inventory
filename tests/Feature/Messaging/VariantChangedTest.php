<?php

declare(strict_types=1);

use App\Models\StockLevel;
use Shopwise\Kit\Messaging\DispatchResult;
use Shopwise\Kit\Messaging\MessageDispatcher;
use Shopwise\Kit\Messaging\OutboxMessage;

it('records a SKU announced by catalog with no stock, exactly once when redelivered', function (): void {
    $envelope = contractExample('catalog.variant-changed.v1');
    $dispatcher = app(MessageDispatcher::class);

    expect($dispatcher->dispatch($envelope, 'catalog.variant-changed'))->toBe(DispatchResult::Handled)
        ->and($dispatcher->dispatch($envelope, 'catalog.variant-changed'))->toBe(DispatchResult::Duplicate);

    $level = StockLevel::query()->sole();
    expect($level->sku)->toBe('TEE-MER-BLK-M')
        ->and($level->active)->toBeTrue()
        ->and($level->on_hand)->toBe(0)
        ->and($level->reserved)->toBe(0)
        ->and($level->catalog_changed_at->toIso8601ZuluString())->toBe('2026-10-26T12:00:00Z')
        ->and(OutboxMessage::query()->count())->toBe(0);
});

it('keeps the stock when catalog deactivates the variant', function (): void {
    StockLevel::factory()->stocked(7)->create(['sku' => 'TEE-MER-BLK-M', 'catalog_changed_at' => '2026-10-26T11:00:00Z']);

    app(MessageDispatcher::class)->dispatch(message('catalog.variant-changed.v1', ['active' => false]), 'catalog.variant-changed');

    $level = StockLevel::query()->findOrFail('TEE-MER-BLK-M');
    expect($level->active)->toBeFalse()->and($level->on_hand)->toBe(7);
});

it('ignores a variant state older than the one it has', function (): void {
    StockLevel::factory()->inactive()->create(['sku' => 'TEE-MER-BLK-M', 'catalog_changed_at' => '2026-10-27T00:00:00Z']);

    app(MessageDispatcher::class)->dispatch(contractExample('catalog.variant-changed.v1'), 'catalog.variant-changed');

    expect(StockLevel::query()->findOrFail('TEE-MER-BLK-M')->active)->toBeFalse();
});
