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
        ];
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
