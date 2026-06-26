<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('goods_dispatch_lines')) {
            return;
        }

        Schema::create('goods_dispatch_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_dispatch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 100);
            $table->string('description', 255);
            $table->string('lot', 100)->nullable();
            $table->unsignedInteger('units_per_pallet')->nullable();
            $table->unsignedInteger('pallets');
            $table->unsignedInteger('requested_units')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_dispatch_lines');
    }
};
