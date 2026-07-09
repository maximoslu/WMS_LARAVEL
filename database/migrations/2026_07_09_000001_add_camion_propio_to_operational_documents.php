<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['goods_receipts', 'goods_dispatches', 'merchandise_requests'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'camion_propio')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('camion_propio')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['goods_receipts', 'goods_dispatches', 'merchandise_requests'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'camion_propio')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('camion_propio');
            });
        }
    }
};
