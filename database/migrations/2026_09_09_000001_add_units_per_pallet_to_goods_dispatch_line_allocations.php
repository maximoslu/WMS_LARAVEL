<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_dispatch_line_allocations', function (Blueprint $table): void {
            $table->unsignedInteger('units_per_pallet')->nullable()->after('location_text');
        });
    }

    public function down(): void
    {
        Schema::table('goods_dispatch_line_allocations', function (Blueprint $table): void {
            $table->dropColumn('units_per_pallet');
        });
    }
};
