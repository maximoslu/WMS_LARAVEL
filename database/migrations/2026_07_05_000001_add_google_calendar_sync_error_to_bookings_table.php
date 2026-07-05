<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bookings', 'google_calendar_sync_error')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table): void {
            $table->text('google_calendar_sync_error')
                ->nullable()
                ->after('google_calendar_synced_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bookings', 'google_calendar_sync_error')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('google_calendar_sync_error');
        });
    }
};
