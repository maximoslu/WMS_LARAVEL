<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('merchandise_requests') || Schema::hasColumn('merchandise_requests', 'service_level')) {
            return;
        }

        Schema::table('merchandise_requests', function (Blueprint $table): void {
            $table->string('service_level', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('merchandise_requests') || ! Schema::hasColumn('merchandise_requests', 'service_level')) {
            return;
        }

        Schema::table('merchandise_requests', function (Blueprint $table): void {
            $table->dropColumn('service_level');
        });
    }
};
