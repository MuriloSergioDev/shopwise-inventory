<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per SKU catalog announced (catalog.variant-changed), with its stock. New SKUs start at zero.
        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->string('sku', 64)->primary();
            $table->boolean('active');
            // Time of the catalog event that last changed `active`: older events arriving late are ignored.
            $table->timestamp('catalog_changed_at', 3);
            // Physically in stock, including units held by open reservations.
            $table->unsignedInteger('on_hand')->default(0);
            // Held by open (not yet committed) reservations. Always <= on_hand.
            $table->unsignedInteger('reserved')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
