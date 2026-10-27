<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\StockLevel;
use Shopwise\Contracts\Events\Inventory\ReservationFailedV1;
use Shopwise\Contracts\Events\Inventory\StockLevelChangedV1;
use Shopwise\Contracts\Events\Inventory\StockReservedV1;
use Shopwise\Kit\Messaging\DispatchResult;
use Shopwise\Kit\Messaging\OutboxMessage;

beforeEach(function (): void {
    $this->freezeSecond();
    StockLevel::factory()->stocked(5)->create(['sku' => 'TEE-MER-BLA-M']);
    StockLevel::factory()->stocked(2, reserved: 1)->create(['sku' => 'SKL-CST']);
});

it('holds every line for 15 minutes and replies stock-reserved keyed by checkoutId', function (): void {
    expect(reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2], ['sku' => 'SKL-CST', 'quantity' => 1]]))->toBe(DispatchResult::Handled);

    $reservation = Reservation::query()->sole();
    expect($reservation->status)->toBe(ReservationStatus::Held)
        ->and(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->reserved)->toBe(2)
        ->and(StockLevel::query()->findOrFail('SKL-CST')->reserved)->toBe(2);

    $events = recordedEvents(StockReservedV1::TYPE);
    expect($events)->toEqual([[
        'reservationId' => $reservation->id,
        'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZ40',
        'lines' => [['sku' => 'TEE-MER-BLA-M', 'quantity' => 2], ['sku' => 'SKL-CST', 'quantity' => 1]],
        'expiresAt' => now()->addMinutes(15)->toIso8601ZuluString(),
        'reservedAt' => now()->toIso8601ZuluString(),
    ]])
        ->and(array_keys($events[0]))->toEqualCanonicalizing(array_keys(contractExample('inventory.stock-reserved.v1')->data))
        ->and(OutboxMessage::query()->where('type', StockReservedV1::TYPE)->sole()->subject)->toBe('01JBCDEFGHJKMNPQRSTVWXYZ40');
});

it('records the new stock level of every reserved SKU', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);

    $events = recordedEvents(StockLevelChangedV1::TYPE);
    expect($events)->toEqual([[
        'sku' => 'TEE-MER-BLA-M',
        'onHand' => 5,
        'reserved' => 2,
        'available' => 3,
        'changedAt' => now()->toIso8601ZuluString(),
    ]])
        ->and(array_keys($events[0]))->toEqualCanonicalizing(array_keys(contractExample('inventory.stock-level-changed.v1')->data))
        ->and(OutboxMessage::query()->where('type', StockLevelChangedV1::TYPE)->sole()->subject)->toBe('TEE-MER-BLA-M');
});

it('holds nothing when one line is short and lists only the short lines', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2], ['sku' => 'SKL-CST', 'quantity' => 2]]);

    expect(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->reserved)->toBe(0)
        ->and(StockLevel::query()->findOrFail('SKL-CST')->reserved)->toBe(1)
        ->and(Reservation::query()->sole()->status)->toBe(ReservationStatus::Failed)
        ->and(recordedEvents(StockLevelChangedV1::TYPE))->toBe([])
        ->and(recordedEvents(StockReservedV1::TYPE))->toBe([]);

    $events = recordedEvents(ReservationFailedV1::TYPE);
    expect($events)->toEqual([[
        'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZ40',
        'reason' => 'insufficient_stock',
        'lines' => [['sku' => 'SKL-CST', 'requested' => 2, 'available' => 1]],
        'failedAt' => now()->toIso8601ZuluString(),
    ]])
        ->and(array_keys($events[0]))->toEqualCanonicalizing(array_keys(contractExample('inventory.reservation-failed.v1')->data));
});

it('sums a SKU listed twice before checking its stock', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 3], ['sku' => 'TEE-MER-BLA-M', 'quantity' => 3]]);

    expect(recordedEvents(ReservationFailedV1::TYPE)[0]['lines'])->toEqual([['sku' => 'TEE-MER-BLA-M', 'requested' => 6, 'available' => 5]]);
});

it('fails with unknown_sku for SKUs catalog never announced or deactivated', function (): void {
    StockLevel::factory()->inactive()->stocked(9)->create(['sku' => 'OLD']);

    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 1], ['sku' => 'NOPE', 'quantity' => 1], ['sku' => 'OLD', 'quantity' => 1]]);

    expect(recordedEvents(ReservationFailedV1::TYPE)[0])->toMatchArray([
        'reason' => 'unknown_sku',
        'lines' => [['sku' => 'NOPE', 'requested' => 1, 'available' => 0], ['sku' => 'OLD', 'requested' => 1, 'available' => 0]],
    ])
        ->and(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->reserved)->toBe(0);
});

it('re-publishes the same outcome for a repeated command without holding more stock', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);
    $this->travel(30)->seconds();
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);

    $events = recordedEvents(StockReservedV1::TYPE);
    expect($events)->toHaveCount(2)
        ->and($events[1])->toEqual($events[0])
        ->and(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->reserved)->toBe(2)
        ->and(OutboxMessage::query()->where('type', StockReservedV1::TYPE)->pluck('id')->unique())->toHaveCount(2);
});

it('keeps a failed outcome even when stock arrives before the command is repeated', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 9]]);
    StockLevel::query()->whereKey('TEE-MER-BLA-M')->update(['on_hand' => 50]);
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 9]]);

    expect(recordedEvents(ReservationFailedV1::TYPE))->toHaveCount(2)
        ->and(recordedEvents(StockReservedV1::TYPE))->toBe([])
        ->and(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->reserved)->toBe(0);
});

it('never reserves more than is available across checkouts', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 3]], '01JBCDEFGHJKMNPQRSTVWXYZA1');
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 3]], '01JBCDEFGHJKMNPQRSTVWXYZA2');
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]], '01JBCDEFGHJKMNPQRSTVWXYZA3');

    expect(Reservation::query()->orderBy('checkout_id')->pluck('status')->all())
        ->toBe([ReservationStatus::Held, ReservationStatus::Failed, ReservationStatus::Held])
        ->and(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->reserved)->toBe(5);
});
