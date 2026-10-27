<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\StockLevel;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Shopwise\Contracts\Events\Inventory\ReservationReleasedV1;

beforeEach(function (): void {
    $this->freezeSecond();
    StockLevel::factory()->stocked(5)->create(['sku' => 'TEE-MER-BLA-M']);
});

it('releases only reservations whose TTL passed and replies reservation-released (expired)', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]], '01JBCDEFGHJKMNPQRSTVWXYZA1');
    $this->travel(10)->minutes();
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 1]], '01JBCDEFGHJKMNPQRSTVWXYZA2');
    $this->travel(5)->minutes();

    $this->artisan('inventory:expire-reservations')->expectsOutput('Expired 1 reservation(s).')->assertSuccessful();

    expect(Reservation::query()->orderBy('checkout_id')->pluck('status')->all())->toBe([ReservationStatus::Released, ReservationStatus::Held])
        ->and(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->reserved)->toBe(1)
        ->and(recordedEvents(ReservationReleasedV1::TYPE))->toEqual([[
            'reservationId' => Reservation::query()->where('checkout_id', '01JBCDEFGHJKMNPQRSTVWXYZA1')->sole()->id,
            'checkoutId' => '01JBCDEFGHJKMNPQRSTVWXYZA1',
            'reason' => 'expired',
            'lines' => [['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]],
            'releasedAt' => now()->toIso8601ZuluString(),
        ]]);

    $this->artisan('inventory:expire-reservations')->expectsOutput('Expired 0 reservation(s).');
    expect(recordedEvents(ReservationReleasedV1::TYPE))->toHaveCount(1);
});

it('leaves committed reservations alone', function (): void {
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);
    dispatchMessage('inventory.commit-reservation.v1', ['reservationId' => Reservation::query()->sole()->id]);
    $this->travel(1)->hours();

    $this->artisan('inventory:expire-reservations')->expectsOutput('Expired 0 reservation(s).');

    expect(StockLevel::query()->findOrFail('TEE-MER-BLA-M')->only(['on_hand', 'reserved']))->toBe(['on_hand' => 3, 'reserved' => 0]);
});

it('honours the configured TTL', function (): void {
    config(['inventory.reservation_ttl_minutes' => 1]);
    reserve([['sku' => 'TEE-MER-BLA-M', 'quantity' => 2]]);
    $this->travel(1)->minutes();

    $this->artisan('inventory:expire-reservations')->expectsOutput('Expired 1 reservation(s).');
});

it('is scheduled every minute without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'inventory:expire-reservations'));

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
