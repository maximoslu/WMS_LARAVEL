<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable();
            $table->string('description')->nullable();
            $table->string('lot')->nullable();
            $table->integer('quantity_units')->default(0);
            $table->integer('units_per_pallet')->nullable();
            $table->integer('pallet_count')->default(0);
            $table->integer('pico_units')->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
    }
};
