<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchandise_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('merchandise_requests', 'completed_with_shortfall')) {
                $table->boolean('completed_with_shortfall')->default(false)->after('completed_at');
            }

            if (! Schema::hasColumn('merchandise_requests', 'remainder_closed_at')) {
                $table->timestamp('remainder_closed_at')->nullable()->after('completed_with_shortfall');
            }

            if (! Schema::hasColumn('merchandise_requests', 'remainder_closed_by')) {
                $table->foreignId('remainder_closed_by')->nullable()->after('remainder_closed_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('merchandise_requests', 'remainder_close_reason')) {
                $table->text('remainder_close_reason')->nullable()->after('remainder_closed_by');
            }

            if (! Schema::hasColumn('merchandise_requests', 'remainder_close_snapshot')) {
                $table->json('remainder_close_snapshot')->nullable()->after('remainder_close_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchandise_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('merchandise_requests', 'remainder_closed_by')) {
                $table->dropConstrainedForeignId('remainder_closed_by');
            }

            foreach ([
                'remainder_close_snapshot',
                'remainder_close_reason',
                'remainder_closed_at',
                'completed_with_shortfall',
            ] as $column) {
                if (Schema::hasColumn('merchandise_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
