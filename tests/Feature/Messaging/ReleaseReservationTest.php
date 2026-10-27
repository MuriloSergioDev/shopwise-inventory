<?php

declare(strict_types=1);

use App\Enums\ReleaseReason;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\StockLevel;
use Shopwise\Contracts\Events\Inventory\ReservationReleasedV1;

beforeEach(function (): void {
    $this->freezeSecond();
    StockLevel::factory()->stocked(5)->create(['sku' => 'TEE-MER-BLA-M']);
});

function release(string $checkoutId = '01JBCDEFGHJKMNPQRSTVWXYZ40'): void
{
    dispatchMessage('inventory.release-reservation.v1', ['checkoutId' => $checkoutId]);
}

it('returns held stock and replies reservation-released (requested) with the lines', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);
    $reservation = Reservation::query()->sole();

    release();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Released)
        ->and($reservation->release_reason)->toBe(ReleaseReason::Requested)
        ->and(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->only(['on_hand', 'reserved']))->toBe(['on_hand' => 5, 'reserved' => 0]);

    $events = recordedEvents(ReservationReleasedV1::TYPE);
    expect($events)->toEqual([[
        'reservationId' => $reservation->id,
        'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZ40',
        'reason' => 'requested',
        'lines' => [['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]],
        'releasedAt' => now()->toIso8601ZuluString(),
    ]])
        ->and(array_keys($events[0]))->toEqualCanonicalizing(array_keys(contractExample('inventory.reservation-released.v1')->data));
});

it('puts committed stock back on hand', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);
    dispatchMessage('inventory.commit-reservation.v1', ['reservationId' => Reservation::query()->sole()->id]);
    expect(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->on_hand)->toBe(3);

    release();

    expect(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->only(['on_hand', 'reserved']))->toBe(['on_hand' => 5, 'reserved' => 0])
        ->and(recordedEvents(ReservationReleasedV1::TYPE)[0]['lines'])->toEqual([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);
});

it('replies with empty lines when released twice, without returning stock twice', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);

    release();
    release();

    $events = recordedEvents(ReservationReleasedV1::TYPE);
    expect($events)->toHaveCount(2)
        ->and($events[1])->toMatchArray(['reservationId' => $events[0]['reservationId'], 'reason' => 'requested', 'lines' => []])
        ->and(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->reserved)->toBe(0);
});

it('replies with a null reservationId when the checkout never had a reservation', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 9]], '01JBCDEFGHJKMNPQRSTVWXYZA1');

    release('01JBCDEFGHJKMNPQRSTVWXYZA1');
    release('01JBCDEFGHJKMNPQRSTVWXYZA2');

    expect(recordedEvents(ReservationReleasedV1::TYPE))->toEqual([
        ['reservationId' => null, 'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZA1', 'reason' => 'requested', 'lines' => [], 'releasedAt' => now()->toIso8601ZuluString()],
        ['reservationId' => null, 'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZA2', 'reason' => 'requested', 'lines' => [], 'releasedAt' => now()->toIso8601ZuluString()],
    ]);
});

it('re-publishes reservation-released when reserve-stock is repeated after the release', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);
    release();

    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);

    expect(recordedEvents(ReservationReleasedV1::TYPE))->toHaveCount(2)
        ->and(recordedEvents(ReservationReleasedV1::TYPE)[1])->toMatchArray(['reason' => 'requested', 'lines' => []])
        ->and(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->reserved)->toBe(0);
});
