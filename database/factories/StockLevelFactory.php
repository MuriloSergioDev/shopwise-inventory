<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StockLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLevel>
 */
final class StockLevelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku' => mb_strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'active' => true,
            'catalog_changed_at' => now(),
            'on_hand' => 0,
            'reserved' => 0,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['active' => false]);
    }

    public function stocked(int $onHand, int $reserved = 0): self
    {
        return $this->state(['on_hand' => $onHand, 'reserved' => $reserved]);
    }
}
