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
        'requested_pallets',
        'loaded_pallets',
        'loading_notes',
        'confirmed_by',
        'confirmed_at',
        'is_extra_line',
        'source_request_line_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'units_per_pallet' => 'integer',
            'pallets' => 'integer',
            'requested_units' => 'integer',
            'requested_pallets' => 'integer',
            'loaded_pallets' => 'integer',
            'confirmed_at' => 'datetime',
            'is_extra_line' => 'boolean',
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

    public function sourceRequestLine(): BelongsTo
    {
        return $this->belongsTo(MerchandiseRequestLine::class, 'source_request_line_id');
    }

    public function requestedPallets(): int
    {
        return (int) ($this->requested_pallets ?? $this->pallets ?? 0);
    }

    public function loadedPallets(): int
    {
        return (int) ($this->loaded_pallets ?? $this->requestedPallets());
    }

    public function hasLoadingDifference(): bool
    {
        return $this->requestedPallets() !== $this->loadedPallets();
    }

    public function lineOriginLabel(): string
    {
        return $this->is_extra_line ? 'Extra' : 'Pedido';
    }
}
