<?php

declare(strict_types=1);

use App\Enums\ReleaseReason;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\StockLevel;
use Shopwise\Contracts\Events\Inventory\ReservationCommittedV1;
use Shopwise\Contracts\Events\Inventory\ReservationReleasedV1;
use Shopwise\Contracts\Events\Inventory\StockLevelChangedV1;
use Shopwise\Kit\Messaging\DispatchResult;
use Shopwise\Kit\Messaging\MessageDispatcher;

beforeEach(function (): void {
    $this->freezeSecond();
    StockLevel::factory()->stocked(5)->create(['sku' => 'TEE-MER-BLA-M']);
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);
    $this->reservation = Reservation::query()->sole();
});

function commit(string $reservationId, string $checkoutId = '01JBCDEFGHJKMNPQRSTVWXYZ40'): DispatchResult
{
    return dispatchMessage('inventory.commit-reservation.v1', ['checkoutId' => $checkoutId, 'reservationId' => $reservationId]);
}

it('takes the held stock off on hand and replies reservation-committed', function (): void {
    $this->travel(1)->minutes();

    expect(commit($this->reservation->id))->toBe(DispatchResult::Handled);

    $level = StockLevel::query()->findOrFail('TEE-MER-BLA-M');
    expect($level->on_hand)->toBe(3)
        ->and($level->reserved)->toBe(0)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Committed)
        ->and(recordedEvents(StockLevelChangedV1::TYPE)[1])->toMatchArray(['onHand' => 3, 'reserved' => 0, 'available' => 3]);

    $events = recordedEvents(ReservationCommittedV1::TYPE);
    expect($events)->toEqual([[
        'reservationId' => $this->reservation->id,
        'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZ40',
        'lines' => [['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]],
        'committedAt' => now()->toIso8601ZuluString(),
    ]])
        ->and(array_keys($events[0]))->toEqualCanonicalizing(array_keys(contractExample('inventory.reservation-committed.v1')->data));
});

it('re-publishes the commit for a repeated command without taking stock twice', function (): void {
    commit($this->reservation->id);
    commit($this->reservation->id);

    $events = recordedEvents(ReservationCommittedV1::TYPE);
    expect($events)->toHaveCount(2)
        ->and($events[1])->toEqual($events[0])
        ->and(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->on_hand)->toBe(3);
});

it('ignores a redelivered envelope', function (): void {
    $envelope = message('inventory.commit-reservation.v1', ['reservationId' => $this->reservation->id]);
    $dispatcher = app(MessageDispatcher::class);

    expect($dispatcher->dispatch($envelope, 'inventory.commit-reservation'))->toBe(DispatchResult::Handled)
        ->and($dispatcher->dispatch($envelope, 'inventory.commit-reservation'))->toBe(DispatchResult::Duplicate)
        ->and(recordedEvents(ReservationCommittedV1::TYPE))->toHaveCount(1);
});

it('expires a reservation past its TTL instead of committing it', function (): void {
    $this->travel(15)->minutes();

    commit($this->reservation->id);

    expect($this->reservation->refresh()->status)->toBe(ReservationStatus::Released)
        ->and($this->reservation->release_reason)->toBe(ReleaseReason::Expired)
        ->and(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->only(['on_hand', 'reserved']))->toBe(['on_hand' => 5, 'reserved' => 0])
        ->and(recordedEvents(ReservationCommittedV1::TYPE))->toBe([])
        ->and(recordedEvents(ReservationReleasedV1::TYPE))->toEqual([[
            'reservationId' => $this->reservation->id,
            'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZ40',
            'reason' => 'expired',
            'lines' => [['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]],
            'releasedAt' => now()->toIso8601ZuluString(),
        ]]);
});

it('answers reservation-released (expired) with no lines when the reservation is already gone', function (): void {
    $this->travel(16)->minutes();
    $this->artisan('inventory:expire-reservations')->assertSuccessful();

    commit($this->reservation->id);

    expect(recordedEvents(ReservationReleasedV1::TYPE))->toHaveCount(2)
        ->and(recordedEvents(ReservationReleasedV1::TYPE)[1])->toMatchArray(['reason' => 'expired', 'lines' => []]);
});

it('answers reservation-released (expired) for a reservation it does not know', function (): void {
    commit('01JBCDEFGHJKMNPQRSTVWXYZ99');
    commit($this->reservation->id, '01JBCDEFGHJKMNPQRSTVWXYZ98');

    expect(recordedEvents(ReservationReleasedV1::TYPE))->toEqual([
        ['reservationId' => '01JBCDEFGHJKMNPQRSTVWXYZ99', 'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZ40', 'reason' => 'expired', 'lines' => [], 'releasedAt' => now()->toIso8601ZuluString()],
        ['reservationId' => $this->reservation->id, 'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZ98', 'reason' => 'expired', 'lines' => [], 'releasedAt' => now()->toIso8601ZuluString()],
    ])
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Held);
});
