<?php

namespace App\Models;

use App\Support\Locations\LocationCode;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'code',
        'name',
        'zone',
        'aisle',
        'rack',
        'level',
        'position',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $location): void {
            $location->code = LocationCode::normalize($location->code);
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockPallets(): HasMany
    {
        return $this->hasMany(StockPallet::class);
    }

    public function defaultItems(): HasMany
    {
        return $this->hasMany(Item::class, 'default_location_id');
    }

    public function goodsReceiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function displayLabel(): string
    {
        $code = LocationCode::normalize($this->code);
        $warehouseName = trim((string) ($this->warehouse?->name ?: $this->warehouse?->code));
        $warehouseIdentity = LocationCode::normalize(($this->warehouse?->code ?? '').' '.$warehouseName);

        if (preg_match('/(^|\s)(NAVE\s*)?38($|\s)/u', $warehouseIdentity) === 1) {
            return 'NAVE 38 - Calle '.$code;
        }

        return $warehouseName !== ''
            ? $warehouseName.' - Ubicacion '.$code
            : 'Ubicacion '.$code;
    }
}
