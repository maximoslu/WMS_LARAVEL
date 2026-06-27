<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('items', 'status')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->string('status', 20)
                    ->default('active')
                    ->after('active');

                $table->foreignId('default_location_id')
                    ->nullable()
                    ->after('status')
                    ->constrained('locations')
                    ->nullOnDelete();

                $table->index(['client_id', 'status']);
            });
        }

        if (! Schema::hasColumn('stock_pallets', 'status')) {
            Schema::table('stock_pallets', function (Blueprint $table): void {
                $table->string('lot', 100)
                    ->nullable()
                    ->after('pallet_code');

                $table->string('status', 20)
                    ->default('available')
                    ->after('received_at');

                $table->string('blocked_reason', 255)
                    ->nullable()
                    ->after('status');

                $table->index(['item_id', 'status', 'received_at']);
                $table->index(['client_id', 'status']);
                $table->index(['lot']);
            });
        }

        DB::table('items')
            ->select(['id', 'active'])
            ->orderBy('id')
            ->chunkById(100, function ($items): void {
                foreach ($items as $item) {
                    DB::table('items')
                        ->where('id', $item->id)
                        ->update([
                            'status' => $item->active ? 'active' : 'blocked',
                        ]);
                }
            });

        DB::table('stock_pallets')
            ->select(['id', 'item_id', 'active'])
            ->orderBy('id')
            ->chunkById(100, function ($pallets): void {
                foreach ($pallets as $pallet) {
                    $lot = DB::table('items')
                        ->where('id', $pallet->item_id)
                        ->value('lot');

                    DB::table('stock_pallets')
                        ->where('id', $pallet->id)
                        ->update([
                            'lot' => $lot,
                            'status' => $pallet->active ? 'available' : 'blocked',
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('stock_pallets', function (Blueprint $table): void {
            $table->dropIndex(['item_id', 'status', 'received_at']);
            $table->dropIndex(['client_id', 'status']);
            $table->dropIndex(['lot']);
            $table->dropColumn(['lot', 'status', 'blocked_reason']);
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_location_id');
            $table->dropIndex(['client_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
