<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Held reservations without lines. Tests that need stock held go through App\Actions\ReserveStock instead,
 * so stock_levels.reserved stays consistent.
 *
 * @extends Factory<Reservation>
 */
final class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'checkout_id' => (string) Str::ulid(),
            'status' => ReservationStatus::Held,
            'expires_at' => now()->addMinutes(15),
        ];
    }
}
