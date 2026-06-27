<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_OBSOLETE = 'obsolete';

    protected $fillable = [
        'client_id',
        'sku',
        'description',
        'lot',
        'lot_key',
        'units_per_pallet',
        'active',
        'status',
        'default_location_id',
    ];

    protected $hidden = [
        'lot_key',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'units_per_pallet' => 'integer',
            'default_location_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $status = $item->status ?: ($item->active ? self::STATUS_ACTIVE : self::STATUS_BLOCKED);

            $item->status = in_array($status, self::statuses(), true)
                ? $status
                : self::STATUS_ACTIVE;
            $item->active = $item->status === self::STATUS_ACTIVE;
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_location_id');
    }

    public function stockPallets(): HasMany
    {
        return $this->hasMany(StockPallet::class);
    }

    public function goodsReceiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function merchandiseRequestLines(): HasMany
    {
        return $this->hasMany(MerchandiseRequestLine::class);
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_BLOCKED,
            self::STATUS_OBSOLETE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_ACTIVE => 'Activo',
            self::STATUS_BLOCKED => 'Bloqueado',
            self::STATUS_OBSOLETE => 'Obsoleto',
        ];
    }

    public static function statusLabelFor(?string $status): string
    {
        return self::statusOptions()[$status ?? ''] ?? 'Activo';
    }

    public function statusLabel(): string
    {
        return self::statusLabelFor($this->status);
    }

    public function isOperational(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
