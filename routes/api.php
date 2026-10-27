<?php

declare(strict_types=1);

use App\Http\Controllers\ListAvailabilityController;
use App\Http\Controllers\ListStockLevelsController;
use App\Http\Controllers\SetStockLevelController;
use App\Http\Controllers\ShowStockLevelController;
use Illuminate\Support\Facades\Route;

// Called only by the BFFs. Contract: shopwise-contracts/openapi/inventory.yaml.
// Reservations are made only through checkout's Kafka commands (ADR-0004), never over HTTP.
Route::get('/stock', ListAvailabilityController::class)->name('stock.index');

Route::middleware('auth.jwt:admin')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/stock', ListStockLevelsController::class)->name('stock.index');
    Route::get('/stock/{sku}', ShowStockLevelController::class)->name('stock.show');
    Route::put('/stock/{sku}', SetStockLevelController::class)->name('stock.update');
});
