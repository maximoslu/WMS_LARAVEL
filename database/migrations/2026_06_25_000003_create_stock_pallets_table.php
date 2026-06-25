<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_pallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('location_text')->nullable();
            $table->string('pallet_code')->nullable();
            $table->unsignedInteger('quantity_units');
            $table->date('received_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['client_id', 'item_id', 'active']);
            $table->index(['item_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_pallets');
    }
};
