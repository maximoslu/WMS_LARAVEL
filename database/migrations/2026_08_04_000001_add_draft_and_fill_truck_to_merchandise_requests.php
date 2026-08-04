<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchandise_request_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('merchandise_request_lines', 'fill_truck')) {
                $table->boolean('fill_truck')->default(false)->after('required_units');
            }
        });

        Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_dispatch_lines', 'fill_truck')) {
                $table->boolean('fill_truck')->default(false)->after('source_request_line_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_dispatch_lines', 'fill_truck')) {
                $table->dropColumn('fill_truck');
            }
        });

        Schema::table('merchandise_request_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('merchandise_request_lines', 'fill_truck')) {
                $table->dropColumn('fill_truck');
            }
        });
    }
};
