<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_pallets', function (Blueprint $table): void {
            $table->foreignId('location_id')
                ->nullable()
                ->after('item_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['location_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_pallets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
