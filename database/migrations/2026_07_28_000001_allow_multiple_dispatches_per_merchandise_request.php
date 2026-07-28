<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' && $this->indexExists('goods_dispatches_merchandise_request_id_unique')) {
            Schema::table('goods_dispatches', function (Blueprint $table): void {
                $table->dropUnique('goods_dispatches_merchandise_request_id_unique');
            });
        }

        Schema::table('goods_dispatches', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_dispatches', 'shipment_sequence')) {
                $table->unsignedInteger('shipment_sequence')->nullable()->after('merchandise_request_id');
            }
        });

        DB::table('goods_dispatches')
            ->whereNotNull('merchandise_request_id')
            ->whereNull('shipment_sequence')
            ->orderBy('id')
            ->update(['shipment_sequence' => 1]);

        Schema::table('goods_dispatches', function (Blueprint $table): void {
            $table->unique(['merchandise_request_id', 'shipment_sequence'], 'goods_dispatches_request_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('goods_dispatches', function (Blueprint $table): void {
            $table->dropUnique('goods_dispatches_request_sequence_unique');
        });

        Schema::table('goods_dispatches', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_dispatches', 'shipment_sequence')) {
                $table->dropColumn('shipment_sequence');
            }
        });

        Schema::table('goods_dispatches', function (Blueprint $table): void {
            $table->unique('merchandise_request_id', 'goods_dispatches_merchandise_request_id_unique');
        });
    }

    private function indexExists(string $indexName): bool
    {
        return collect(DB::select('SHOW INDEX FROM goods_dispatches'))
            ->contains(fn (object $index): bool => (string) $index->Key_name === $indexName);
    }
};
