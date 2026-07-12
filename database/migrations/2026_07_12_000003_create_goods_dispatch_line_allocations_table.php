<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('goods_dispatch_line_allocations')) {
            return;
        }

        Schema::create('goods_dispatch_line_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_dispatch_line_id')
                ->constrained('goods_dispatch_lines')
                ->cascadeOnDelete();
            $table->foreignId('stock_pallet_id')
                ->constrained('stock_pallets')
                ->restrictOnDelete();
            $table->string('lot', 100)->nullable();
            $table->string('location_text', 255)->nullable();
            $table->unsignedInteger('loaded_pallets')->default(0);
            $table->unsignedInteger('loaded_partial_units')->default(0);
            $table->json('selected_peaks')->nullable();
            $table->timestamps();

            $table->index(['goods_dispatch_line_id', 'stock_pallet_id'], 'gdla_line_stock_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_dispatch_line_allocations');
    }
};
