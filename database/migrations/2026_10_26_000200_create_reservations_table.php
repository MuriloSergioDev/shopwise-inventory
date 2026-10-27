<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The outcome of a checkout's inventory.reserve-stock command: one row per checkout (ADR-0004).
        Schema::create('reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('checkout_id', 26)->unique();
            // held, committed, released or failed (App\Enums\ReservationStatus).
            $table->string('status', 16);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 16)->nullable();
            // Only for failed reservations: why, and the lines that could not be held ({sku, requested, available}).
            $table->string('failure_reason', 32)->nullable();
            $table->json('failed_lines')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('reservation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64)->index();
            $table->unsignedSmallInteger('quantity');

            $table->foreign('sku')->references('sku')->on('stock_levels');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_lines');
        Schema::dropIfExists('reservations');
    }
};
