<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchandise_request_lines') && ! Schema::hasColumn('merchandise_request_lines', 'destination_location')) {
            Schema::table('merchandise_request_lines', function (Blueprint $table): void {
                $table->string('destination_location', 255)->nullable()->after('lot');
            });
        }

        if (Schema::hasTable('goods_dispatch_lines') && ! Schema::hasColumn('goods_dispatch_lines', 'destination_location')) {
            Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
                $table->string('destination_location', 255)->nullable()->after('lot');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('goods_dispatch_lines') && Schema::hasColumn('goods_dispatch_lines', 'destination_location')) {
            Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
                $table->dropColumn('destination_location');
            });
        }

        if (Schema::hasTable('merchandise_request_lines') && Schema::hasColumn('merchandise_request_lines', 'destination_location')) {
            Schema::table('merchandise_request_lines', function (Blueprint $table): void {
                $table->dropColumn('destination_location');
            });
        }
    }
};
