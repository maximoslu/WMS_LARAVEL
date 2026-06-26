<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchandise_request_events')) {
            Schema::table('merchandise_request_events', function (Blueprint $table): void {
                $table->index(['merchandise_request_id', 'event_type'], 'mr_events_req_event_idx');
            });

            return;
        }

        Schema::create('merchandise_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchandise_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['merchandise_request_id', 'event_type'], 'mr_events_req_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchandise_request_events');
    }
};
