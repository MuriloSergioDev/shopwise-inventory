<?php

declare(strict_types=1);

/*
 * Child process of tests/Feature/Messaging/ConcurrentReservationsTest.php: boots the app on its own DB connection,
 * prints "ready", waits for a line on stdin (all children are released at once), reserves stock and prints the
 * reservation status.
 * Usage: php tests/Support/reserve-stock.php <checkoutId> <lines JSON>
 */

use App\Actions\ReserveStock;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $checkoutId, $lines] = $argv;
/** @var list<array{sku: string, quantity: int}> $decoded */
$decoded = json_decode($lines, true, flags: JSON_THROW_ON_ERROR);
$reserveStock = $app->make(ReserveStock::class);

// Straight to STDOUT: `echo` goes through output buffering, so the parent would never see it before exit.
fwrite(STDOUT, "ready\n");
fgets(STDIN);

echo $reserveStock->handle($checkoutId, $decoded)->status->value;
