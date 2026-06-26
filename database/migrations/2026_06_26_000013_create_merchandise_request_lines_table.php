<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchandise_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchandise_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('lot', 100)->nullable();
            $table->unsignedInteger('requested_pallets');
            $table->unsignedInteger('units_per_pallet');
            $table->unsignedInteger('requested_units');
            $table->unsignedInteger('prepared_pallets')->nullable();
            $table->unsignedInteger('prepared_units')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'lot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchandise_request_lines');
    }
};
