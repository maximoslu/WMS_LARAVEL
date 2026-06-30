<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('booking_code')->nullable()->unique();
            $table->string('type', 20);
            $table->string('status', 20);
            $table->date('scheduled_date');
            $table->time('scheduled_time_from')->nullable();
            $table->time('scheduled_time_to')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('carrier_name')->nullable();
            $table->string('vehicle_plate')->nullable();
            $table->string('driver_name')->nullable();
            $table->integer('pallets_expected')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('origin_destination')->nullable();
            $table->string('document_reference')->nullable();
            $table->string('loading_dock')->nullable();
            $table->string('google_calendar_event_id')->nullable();
            $table->timestamp('google_calendar_synced_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'scheduled_date']);
            $table->index(['status', 'scheduled_date']);
            $table->index(['type', 'scheduled_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
