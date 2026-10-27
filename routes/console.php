<?php

declare(strict_types=1);

use App\Actions\ExpireReservations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inventory:expire-reservations', function (ExpireReservations $expireReservations): void {
    $this->info("Expired {$expireReservations->handle()} reservation(s).");
})->purpose('Release held reservations whose TTL passed and reply inventory.reservation-released (expired)');

Schedule::command('inventory:expire-reservations')->everyMinute()->withoutOverlapping();
