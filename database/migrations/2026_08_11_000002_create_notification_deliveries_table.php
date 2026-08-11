<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->char('idempotency_key', 64)->unique();
            $table->uuid('provider_idempotency_key')->unique();
            $table->string('type', 100);
            $table->string('source_type', 100);
            $table->string('source_id', 100);
            $table->string('event_version', 191)->nullable();
            $table->string('channel', 30);
            $table->char('recipient_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('processing_token')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['status', 'updated_at']);
            $table->index(['type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
