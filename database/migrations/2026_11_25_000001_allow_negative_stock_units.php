<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_pallets', function (Blueprint $table): void {
            $table->bigInteger('quantity_units')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('stock_pallets')->where('quantity_units', '<', 0)->exists()
            || DB::table('stock_pallets')->where('quantity_units', '>', 4294967295)->exists()) {
            throw new RuntimeException('No se puede revertir: hay saldos fuera del rango de la columna anterior.');
        }

        Schema::table('stock_pallets', function (Blueprint $table): void {
            $table->unsignedInteger('quantity_units')->change();
        });
    }
};
