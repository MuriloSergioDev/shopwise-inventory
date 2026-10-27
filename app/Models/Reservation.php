<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReleaseReason;
use App\Enums\ReservationFailure;
use App\Enums\ReservationStatus;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The outcome of one checkout's inventory.reserve-stock command (one per checkout_id). Held reservations count in
 * stock_levels.reserved until they are committed, released or expire.
 *
 * @property string $id
 * @property string $checkout_id
 * @property ReservationStatus $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $committed_at
 * @property Carbon|null $released_at
 * @property ReleaseReason|null $release_reason
 * @property ReservationFailure|null $failure_reason
 * @property list<array{sku: string, requested: int, available: int}>|null $failed_lines
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, ReservationLine> $lines
 */
#[Fillable(['checkout_id', 'status', 'expires_at', 'committed_at', 'released_at', 'release_reason', 'failure_reason', 'failed_lines'])]
final class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @return HasMany<ReservationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ReservationLine::class)->orderBy('id');
    }

    public function isExpired(): bool
    {
        return $this->status === ReservationStatus::Held && $this->expires_at?->lessThanOrEqualTo(now()) === true;
    }

    /**
     * Units per SKU, summed over lines (a command may list a SKU twice).
     *
     * @return array<string, int>
     */
    public function quantitiesBySku(): array
    {
        $quantities = [];
        foreach ($this->lines as $line) {
            $quantities[$line->sku] = ($quantities[$line->sku] ?? 0) + $line->quantity;
        }

        return $quantities;
    }

    /**
     * The `lines` of the reservation events: as the command listed them.
     *
     * @return list<array{sku: string, quantity: int}>
     */
    public function eventLines(): array
    {
        return array_values($this->lines->map(fn (ReservationLine $line): array => ['sku' => $line->sku, 'quantity' => $line->quantity])->all());
    }

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'expires_at' => 'datetime',
            'committed_at' => 'datetime',
            'released_at' => 'datetime',
            'release_reason' => ReleaseReason::class,
            'failure_reason' => ReservationFailure::class,
            'failed_lines' => 'array',
        ];
    }
}
