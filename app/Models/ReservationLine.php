<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $reservation_id
 * @property string $sku
 * @property int $quantity
 * @property-read Reservation $reservation
 */
#[Fillable(['sku', 'quantity'])]
#[WithoutTimestamps]
final class ReservationLine extends Model
{
    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
