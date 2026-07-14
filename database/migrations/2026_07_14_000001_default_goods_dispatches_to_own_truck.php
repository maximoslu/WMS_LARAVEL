<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('goods_dispatches') || ! Schema::hasColumn('goods_dispatches', 'camion_propio')) {
            return;
        }

        Schema::table('goods_dispatches', function (Blueprint $table): void {
            $table->boolean('camion_propio')->default(true)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('goods_dispatches') || ! Schema::hasColumn('goods_dispatches', 'camion_propio')) {
            return;
        }

        Schema::table('goods_dispatches', function (Blueprint $table): void {
            $table->boolean('camion_propio')->default(false)->change();
        });
    }
};
