<?php

declare(strict_types=1);

use App\Models\Reservation;
use App\Models\StockLevel;
use Shopwise\Contracts\Events\Inventory\StockLevelChangedV1;
use Shopwise\Kit\Messaging\OutboxMessage;

beforeEach(function (): void {
    $this->freezeSecond();
});

it('sets on hand and records stock-level-changed keyed by sku', function (): void {
    StockLevel::factory()->create(['sku' => 'TEE-MER-BLA-M']);

    $this->putJson('/api/admin/stock/TEE-MER-BLA-M', ['onHand' => 12], bearer())
        ->assertOk()
        ->assertExactJson(['data' => [
            'sku' => 'TEE-MER-BLA-M',
            'onHand' => 12,
            'reserved' => 0,
            'available' => 12,
            'updatedAt' => now()->toIso8601ZuluString(),
        ]]);

    expect(recordedEvents(StockLevelChangedV1::TYPE))->toEqual([[
        'sku' => 'TEE-MER-BLA-M', 'onHand' => 12, 'reserved' => 0, 'available' => 12, 'changedAt' => now()->toIso8601ZuluString(),
    ]])
        ->and(OutboxMessage::query()->sole()->subject)->toBe('TEE-MER-BLA-M');
});

it('records nothing when the count does not change', function (): void {
    StockLevel::factory()->stocked(12)->create(['sku' => 'TEE']);

    $this->putJson('/api/admin/stock/TEE', ['onHand' => 12], bearer())->assertOk()->assertJsonPath('data.onHand', 12);

    expect(recordedEvents(StockLevelChangedV1::TYPE))->toBe([]);
});

it('refuses an on-hand count below what open reservations hold', function (): void {
    StockLevel::factory()->stocked(5)->create(['sku' => 'TEE']);
    reserve([['sku' => 'TEE', 'quantity' => 3]]);

    $this->putJson('/api/admin/stock/TEE', ['onHand' => 2], bearer())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('onHand');
    $this->putJson('/api/admin/stock/TEE', ['onHand' => 3], bearer())
        ->assertOk()
        ->assertJsonPath('data.available', 0);
});

it('validates the count', function (mixed $onHand): void {
    StockLevel::factory()->create(['sku' => 'TEE']);

    $this->putJson('/api/admin/stock/TEE', ['onHand' => $onHand], bearer())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('onHand');
})->with([-1, 1.5, 1_000_001, null, 'ten']);

it('returns 404 for a SKU catalog never announced', function (): void {
    $this->putJson('/api/admin/stock/NOPE', ['onHand' => 1], bearer())->assertNotFound();
    $this->getJson('/api/admin/stock/NOPE', bearer())->assertNotFound();

    expect(StockLevel::query()->count())->toBe(0);
});

it('shows a SKU with its open reservations', function (): void {
    StockLevel::factory()->stocked(10)->create(['sku' => 'TEE']);
    StockLevel::factory()->stocked(10)->create(['sku' => 'CAP']);
    reserve([['sku' => 'TEE', 'quantity' => 1], ['sku' => 'CAP', 'quantity' => 1], ['sku' => 'TEE', 'quantity' => 2]], '01JBCDEFGHJKMNPQRSTVWXYZA1');
    reserve([['sku' => 'TEE', 'quantity' => 1]], '01JBCDEFGHJKMNPQRSTVWXYZA2');
    reserve([['sku' => 'TEE', 'quantity' => 4]], '01JBCDEFGHJKMNPQRSTVWXYZA3');
    dispatchMessage('inventory.release-reservation.v1', ['checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZA2']);
    $first = Reservation::query()->where('checkout_id', '01JBCDEFGHJKMNPQRSTVWXYZA1')->sole();
    $third = Reservation::query()->where('checkout_id', '01JBCDEFGHJKMNPQRSTVWXYZA3')->sole();

    $this->getJson('/api/admin/stock/TEE', bearer())
        ->assertOk()
        ->assertExactJson(['data' => [
            'sku' => 'TEE',
            'onHand' => 10,
            'reserved' => 7,
            'available' => 3,
            'updatedAt' => now()->toIso8601ZuluString(),
            'reservations' => [
                ['id' => $first->id, 'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZA1', 'quantity' => 3, 'status' => 'held', 'expiresAt' => now()->addMinutes(15)->toIso8601ZuluString()],
                ['id' => $third->id, 'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZA3', 'quantity' => 4, 'status' => 'held', 'expiresAt' => now()->addMinutes(15)->toIso8601ZuluString()],
            ],
        ]]);
});

it('lists stock levels by SKU with a prefix filter and pagination', function (): void {
    foreach (['TEE-B', 'TEE-A', 'TEE-C', 'CAP'] as $sku) {
        StockLevel::factory()->stocked(4)->create(['sku' => $sku]);
    }

    $this->getJson('/api/admin/stock?q=tee&perPage=2&page=2', bearer())
        ->assertOk()
        ->assertJsonPath('data.*.sku', ['TEE-C'])
        ->assertJsonPath('meta', ['page' => 2, 'perPage' => 2, 'total' => 3, 'lastPage' => 2]);

    $this->getJson('/api/admin/stock', bearer())
        ->assertOk()
        ->assertJsonPath('data.*.sku', ['CAP', 'TEE-A', 'TEE-B', 'TEE-C'])
        ->assertJsonPath('meta.perPage', 25);
});

it('treats % and _ in the prefix literally', function (): void {
    StockLevel::factory()->create(['sku' => 'TEE-A']);

    $this->getJson('/api/admin/stock?q=%25', bearer())->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/admin/stock?q=TEE_', bearer())->assertOk()->assertJsonPath('data', []);
});

it('filters on low available stock', function (): void {
    StockLevel::factory()->stocked(10, reserved: 8)->create(['sku' => 'A']);
    StockLevel::factory()->stocked(3)->create(['sku' => 'B']);
    StockLevel::factory()->stocked(0)->create(['sku' => 'C']);
    StockLevel::factory()->stocked(4)->create(['sku' => 'D']);

    $this->getJson('/api/admin/stock?lowStock=3', bearer())
        ->assertOk()
        ->assertJsonPath('data.*.sku', ['A', 'B', 'C']);
    $this->getJson('/api/admin/stock?lowStock=0', bearer())
        ->assertOk()
        ->assertJsonPath('data.*.sku', ['C']);
});

it('validates the list filters', function (): void {
    $this->getJson('/api/admin/stock?lowStock=-1&perPage=101&page=0', bearer())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lowStock', 'perPage', 'page']);
});

it('needs an admin token', function (string $method, string $uri): void {
    StockLevel::factory()->create(['sku' => 'TEE']);

    $this->json($method, $uri, ['onHand' => 1])->assertUnauthorized();
    $this->json($method, $uri, ['onHand' => 1], bearer(['customer']))->assertForbidden();
})->with([
    ['GET', '/api/admin/stock'],
    ['GET', '/api/admin/stock/TEE'],
    ['PUT', '/api/admin/stock/TEE'],
]);
