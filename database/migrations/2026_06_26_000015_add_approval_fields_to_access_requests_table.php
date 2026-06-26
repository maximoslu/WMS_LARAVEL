<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_requests', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->foreignId('user_id')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->after('user_id')->constrained('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('access_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('rejection_reason');
            $table->dropColumn('rejected_at');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn('approved_at');
            $table->dropConstrainedForeignId('approved_by');
        });
    }
};
