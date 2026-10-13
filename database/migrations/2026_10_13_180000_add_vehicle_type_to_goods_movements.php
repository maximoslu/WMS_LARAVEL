<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['goods_receipts', 'goods_dispatches'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('vehicle_type', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['goods_receipts', 'goods_dispatches'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('vehicle_type'));
        }
    }
};
