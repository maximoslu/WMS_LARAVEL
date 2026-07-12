<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsDispatchLineAllocation extends Model
{
    protected $fillable = [
        'goods_dispatch_line_id',
        'stock_pallet_id',
        'lot',
        'location_text',
        'loaded_pallets',
        'loaded_partial_units',
        'selected_peaks',
    ];

    protected function casts(): array
    {
        return [
            'stock_pallet_id' => 'integer',
            'loaded_pallets' => 'integer',
            'loaded_partial_units' => 'integer',
            'selected_peaks' => 'array',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(GoodsDispatchLine::class, 'goods_dispatch_line_id');
    }

    public function stockPallet(): BelongsTo
    {
        return $this->belongsTo(StockPallet::class);
    }

    public function loadedUnits(int $unitsPerPallet): int
    {
        return ($this->loadedPallets() * max(0, $unitsPerPallet)) + $this->loadedPartialUnits();
    }

    public function loadedPallets(): int
    {
        return max(0, (int) $this->loaded_pallets);
    }

    public function loadedPartialUnits(): int
    {
        return max(0, (int) $this->loaded_partial_units);
    }

    /**
     * @return array<int, int>
     */
    public function selectedPeakUnitsByIndex(): array
    {
        $peaks = [];

        foreach ((array) ($this->selected_peaks ?? []) as $peak) {
            if (! is_array($peak)) {
                continue;
            }

            $index = (int) ($peak['index'] ?? 0);
            $units = (int) ($peak['units'] ?? 0);

            if ($index > 0 && $units > 0) {
                $peaks[$index] = $units;
            }
        }

        return $peaks;
    }
}
