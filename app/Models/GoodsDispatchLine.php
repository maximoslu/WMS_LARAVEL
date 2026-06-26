<?php

namespace App\Models;

use Database\Factories\GoodsDispatchLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsDispatchLine extends Model
{
    /** @use HasFactory<GoodsDispatchLineFactory> */
    use HasFactory;

    protected $fillable = [
        'goods_dispatch_id',
        'item_id',
        'sku',
        'description',
        'lot',
        'units_per_pallet',
        'pallets',
        'requested_units',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'units_per_pallet' => 'integer',
            'pallets' => 'integer',
            'requested_units' => 'integer',
        ];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(GoodsDispatch::class, 'goods_dispatch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
