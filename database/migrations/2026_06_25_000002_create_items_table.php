<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 100);
            $table->string('description', 255);
            $table->string('lot', 100)->nullable();
            $table->string('lot_key', 100)->default('');
            $table->unsignedInteger('units_per_pallet');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'sku', 'lot_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
