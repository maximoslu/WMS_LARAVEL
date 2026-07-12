<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_dispatch_lines', 'loaded_partial_units')) {
                $table->unsignedInteger('loaded_partial_units')
                    ->nullable()
                    ->after('loaded_peaks');
            }
        });
    }

    public function down(): void
    {
        Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_dispatch_lines', 'loaded_partial_units')) {
                $table->dropColumn('loaded_partial_units');
            }
        });
    }
};
