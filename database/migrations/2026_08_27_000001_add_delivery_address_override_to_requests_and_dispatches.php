<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['merchandise_requests', 'goods_dispatches'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'delivery_address_override')) {
                    $table->boolean('delivery_address_override')->default(false);
                }

                if (! Schema::hasColumn($tableName, 'delivery_address_text')) {
                    $table->text('delivery_address_text')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['merchandise_requests', 'goods_dispatches'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                foreach (['delivery_address_text', 'delivery_address_override'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
