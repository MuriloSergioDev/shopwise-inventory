<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\RecordSku;
use App\Actions\SetStockLevel;
use App\Models\StockLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Stock for every SKU of catalog's demo seeder. The SKUs are also written as catalog would announce them, so
 * inventory works without Kafka; they get an old timestamp so the real catalog events, when consumed, win.
 * Goes through the actions, so the cluster also sees inventory.stock-level-changed. Running it again does nothing.
 */
final class DatabaseSeeder extends Seeder
{
    /**
     * SKU prefix => [on hand per SKU, SKU suffixes]. Mirrors shopwise-catalog's DatabaseSeeder
     * (prefix + first three letters of each option, uppercased). Low counts on a few SKUs make the admin's
     * lowStock filter and an out-of-stock checkout easy to demo.
     *
     * @var array<string, array{int, list<string>}>
     */
    private const array PRODUCTS = [
        'TEE-MER' => [50, ['BLA-S', 'BLA-M', 'BLA-L', 'NAV-S', 'NAV-M', 'NAV-L']],
        'SHI-LIN' => [20, ['WHI-M', 'WHI-L', 'SAG-M', 'SAG-L']],
        'HOO-HVY' => [25, ['GRE-S', 'GRE-M', 'GRE-L', 'GRE-XL', 'BLA-S', 'BLA-M', 'BLA-L', 'BLA-XL']],
        'JEA-SEL' => [10, ['IND-30', 'IND-32', 'IND-34']],
        'SHO-CHI' => [15, ['KHA-30', 'KHA-32', 'NAV-30', 'NAV-32']],
        'SKL-CST' => [3, ['']],
        'COF-POUR' => [2, ['WHI', 'CHA']],
    ];

    public function run(RecordSku $recordSku, SetStockLevel $setStockLevel): void
    {
        if (StockLevel::query()->where('on_hand', '>', 0)->exists()) {
            return;
        }

        $seededAt = CarbonImmutable::parse('2000-01-01T00:00:00Z');
        foreach (self::PRODUCTS as $prefix => [$onHand, $suffixes]) {
            foreach ($suffixes as $suffix) {
                $sku = $suffix === '' ? $prefix : "{$prefix}-{$suffix}";
                $recordSku->handle($sku, true, $seededAt);
                $setStockLevel->handle($sku, $onHand);
            }
        }
    }
}
