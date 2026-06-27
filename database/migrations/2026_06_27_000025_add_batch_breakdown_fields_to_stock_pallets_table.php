<?php

use App\Support\Stock\StockBatchCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_pallets', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_pallets', 'units_per_pallet')) {
                $table->unsignedInteger('units_per_pallet')
                    ->nullable()
                    ->after('quantity_units');
            }

            if (! Schema::hasColumn('stock_pallets', 'full_pallets')) {
                $table->unsignedInteger('full_pallets')
                    ->default(0)
                    ->after('units_per_pallet');
            }

            if (! Schema::hasColumn('stock_pallets', 'peaks_count')) {
                $table->unsignedTinyInteger('peaks_count')
                    ->default(0)
                    ->after('full_pallets');
            }

            foreach (range(1, 8) as $peakNumber) {
                if (! Schema::hasColumn('stock_pallets', 'peak_'.$peakNumber)) {
                    $table->unsignedInteger('peak_'.$peakNumber)
                        ->default(0)
                        ->after($peakNumber === 1 ? 'peaks_count' : 'peak_'.($peakNumber - 1));
                }
            }

            if (! Schema::hasColumn('stock_pallets', 'notes')) {
                $table->text('notes')
                    ->nullable()
                    ->after('blocked_reason');
            }
        });

        DB::table('stock_pallets')
            ->select(['id', 'item_id', 'quantity_units', 'units_per_pallet'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $unitsPerPallet = (int) ($row->units_per_pallet ?: DB::table('items')
                        ->where('id', $row->item_id)
                        ->value('units_per_pallet'));

                    if ($unitsPerPallet <= 0) {
                        continue;
                    }

                    $breakdown = StockBatchCalculator::calculateBreakdown((int) $row->quantity_units, $unitsPerPallet);

                    DB::table('stock_pallets')
                        ->where('id', $row->id)
                        ->update([
                            'units_per_pallet' => $unitsPerPallet,
                            'full_pallets' => $breakdown['full_pallets'],
                            'peaks_count' => $breakdown['peaks_count'],
                            'peak_1' => $breakdown['peak_1'],
                            'peak_2' => $breakdown['peak_2'],
                            'peak_3' => $breakdown['peak_3'],
                            'peak_4' => $breakdown['peak_4'],
                            'peak_5' => $breakdown['peak_5'],
                            'peak_6' => $breakdown['peak_6'],
                            'peak_7' => $breakdown['peak_7'],
                            'peak_8' => $breakdown['peak_8'],
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('stock_pallets', function (Blueprint $table): void {
            $columns = [
                'units_per_pallet',
                'full_pallets',
                'peaks_count',
                'peak_1',
                'peak_2',
                'peak_3',
                'peak_4',
                'peak_5',
                'peak_6',
                'peak_7',
                'peak_8',
                'notes',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('stock_pallets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
