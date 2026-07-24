<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchandise_request_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('required_units')->nullable()->after('requested_units');
        });
    }

    public function down(): void
    {
        Schema::table('merchandise_request_lines', function (Blueprint $table): void {
            $table->dropColumn('required_units');
        });
    }
};
