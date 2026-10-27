<?php

declare(strict_types=1);

use App\Models\StockLevel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Shopwise\Contracts\Events\Inventory\StockReservedV1;

/*
 * Real concurrency: separate PHP processes, each with its own Postgres connection, reserve at the same instant.
 * Setup and cleanup use a second connection because RefreshDatabase keeps the test's own connection inside a
 * transaction the child processes cannot see.
 */

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs Postgres: in-memory SQLite cannot be shared with other processes.');
    }

    config(['database.connections.committed' => config('database.connections.pgsql')]);
    $this->committed = DB::connection('committed');
    $this->cleanUp = function (Connection $db): void {
        $db->table('reservation_lines')->delete();
        $db->table('reservations')->delete();
        $db->table('stock_levels')->whereIn('sku', ['RACE-A', 'RACE-B'])->delete();
        $db->table('outbox_messages')->delete();
    };
    ($this->cleanUp)($this->committed);
});

afterEach(function (): void {
    if (property_exists($this, 'committed') && $this->committed !== null) {
        ($this->cleanUp)($this->committed);
        DB::purge('committed');
    }
});

/**
 * Starts one process per checkout; all of them reserve at the same moment. Returns each process's output.
 *
 * @param  array<string, list<array{sku: string, quantity: int}>>  $linesByCheckout
 * @return list<string>
 */
function reserveConcurrently(array $linesByCheckout): array
{
    $env = [...getenv(), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => config()->string('database.connections.pgsql.database'), 'APP_ENV' => 'testing'];
    $processes = [];
    foreach ($linesByCheckout as $checkoutId => $lines) {
        $command = [PHP_BINARY, base_path('tests/Support/reserve-stock.php'), $checkoutId, json_encode($lines, JSON_THROW_ON_ERROR)];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $processes[] = [$process, $pipes];
    }

    // Wait until every child has booted, then release them together. Stdin is closed either way, so a child that
    // failed to boot cannot leave the others (and proc_close) waiting forever.
    try {
        foreach ($processes as [, $pipes]) {
            $line = fgets($pipes[1]);
            if ($line !== "ready\n") {
                throw new RuntimeException('A child failed to boot: '.$line.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            }
        }
    } finally {
        foreach ($processes as [, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }
    }

    $outputs = [];
    foreach ($processes as [$process, $pipes]) {
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);
        expect($exitCode)->toBe(0, $errors.$output);
        $outputs[] = $output;
    }

    return $outputs;
}

it('never oversells when many checkouts reserve the last units at once', function (): void {
    $now = now();
    $this->committed->table('stock_levels')->insert([
        ['sku' => 'RACE-A', 'active' => true, 'catalog_changed_at' => $now, 'on_hand' => 5, 'reserved' => 0, 'created_at' => $now, 'updated_at' => $now],
        ['sku' => 'RACE-B', 'active' => true, 'catalog_changed_at' => $now, 'on_hand' => 5, 'reserved' => 0, 'created_at' => $now, 'updated_at' => $now],
    ]);

    // Twelve checkouts each want one A and one B; half list them in the opposite order (deadlock bait).
    $linesByCheckout = [];
    foreach (range(1, 12) as $i) {
        $lines = [['sku' => 'RACE-A', 'quantity' => 1], ['sku' => 'RACE-B', 'quantity' => 1]];
        $linesByCheckout[sprintf('01JBCDEFGHJKMNPQRSTVWXY%03d', $i)] = $i % 2 === 0 ? array_reverse($lines) : $lines;
    }

    $outcomes = array_count_values(reserveConcurrently($linesByCheckout));

    expect($outcomes)->toEqual(['held' => 5, 'failed' => 7])
        ->and(StockLevel::query()->orderBy('sku')->get()->map->only(['sku', 'on_hand', 'reserved'])->all())->toBe([
            ['sku' => 'RACE-A', 'on_hand' => 5, 'reserved' => 5],
            ['sku' => 'RACE-B', 'on_hand' => 5, 'reserved' => 5],
        ])
        ->and(recordedEvents(StockReservedV1::TYPE))->toHaveCount(5);
});
