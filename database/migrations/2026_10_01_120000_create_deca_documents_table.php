<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deca_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('submission_key');
            $table->unique(['created_by', 'submission_key']);
            $table->string('carrier_key', 20)->index();
            $table->date('transport_date')->index();
            $table->json('snapshot');
            $table->string('public_token', 64)->unique();
            $table->text('public_url');
            $table->string('pdf_path');
            $table->char('pdf_sha256', 64);
            $table->unsignedInteger('pdf_size');
            $table->timestamp('issued_at');
            $table->timestamp('retain_until');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deca_documents');
    }
};
