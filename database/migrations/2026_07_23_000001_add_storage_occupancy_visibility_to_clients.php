<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('show_storage_occupancy_to_client')->default(true)->after('active');
        });

        DB::table('clients')
            ->where(function ($query): void {
                $query
                    ->where('code', 'FRIESLAND')
                    ->orWhere('name', 'FRIESLAND');
            })
            ->update(['show_storage_occupancy_to_client' => false]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('show_storage_occupancy_to_client');
        });
    }
};
