<?php

namespace App\Models;

use Database\Factories\GoodsReceiptLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    /** @use HasFactory<GoodsReceiptLineFactory> */
    use HasFactory;

    public const MAX_PEAK_COLUMNS = 10;

    protected $fillable = [
        'goods_receipt_id',
        'item_id',
        'sku',
        'description',
        'lot',
        'quantity_units',
        'units_per_pallet',
        'pallet_count',
        'pico_units',
        'peak_1',
        'peak_2',
        'peak_3',
        'peak_4',
        'peak_5',
        'peak_6',
        'peak_7',
        'peak_8',
        'peak_9',
        'peak_10',
        'location_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_units' => 'integer',
            'units_per_pallet' => 'integer',
            'pallet_count' => 'integer',
            'pico_units' => 'integer',
            'peak_1' => 'integer',
            'peak_2' => 'integer',
            'peak_3' => 'integer',
            'peak_4' => 'integer',
            'peak_5' => 'integer',
            'peak_6' => 'integer',
            'peak_7' => 'integer',
            'peak_8' => 'integer',
            'peak_9' => 'integer',
            'peak_10' => 'integer',
        ];
    }

    /** @return list<int> */
    public function peakUnits(): array
    {
        $peaks = collect(range(1, self::MAX_PEAK_COLUMNS))
            ->map(fn (int $number): int => (int) ($this->{'peak_'.$number} ?? 0))
            ->filter(fn (int $value): bool => $value > 0)
            ->values()
            ->all();

        if ($peaks === [] && (int) ($this->pico_units ?? 0) > 0) {
            return [(int) $this->pico_units];
        }

        return $peaks;
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
