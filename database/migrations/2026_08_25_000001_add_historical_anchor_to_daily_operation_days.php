<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_operation_days', function (Blueprint $table): void {
            $table->boolean('is_historical_anchor')->default(false)->after('expected_pallets_tomorrow');
        });
    }

    public function down(): void
    {
        Schema::table('daily_operation_days', function (Blueprint $table): void {
            $table->dropColumn('is_historical_anchor');
        });
    }
};
