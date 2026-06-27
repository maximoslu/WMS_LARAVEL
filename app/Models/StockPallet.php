<?php

namespace App\Models;

use Database\Factories\StockPalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class StockPallet extends Model
{
    /** @use HasFactory<StockPalletFactory> */
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_OBSOLETE = 'obsolete';

    protected $fillable = [
        'client_id',
        'item_id',
        'goods_receipt_id',
        'location_id',
        'location_text',
        'pallet_code',
        'lot',
        'quantity_units',
        'received_at',
        'status',
        'blocked_reason',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'quantity_units' => 'integer',
            'received_at' => 'date',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $stockPallet): void {
            $itemClientId = $stockPallet->item?->client_id
                ?? Item::query()->whereKey($stockPallet->item_id)->value('client_id');

            if ($itemClientId === null) {
                throw ValidationException::withMessages([
                    'item_id' => 'El articulo seleccionado no existe.',
                ]);
            }

            if ($stockPallet->client_id !== null && (int) $stockPallet->client_id !== (int) $itemClientId) {
                throw ValidationException::withMessages([
                    'client_id' => 'El cliente del palet debe coincidir con el cliente del articulo.',
                ]);
            }

            $stockPallet->client_id = (int) $itemClientId;

            if ($stockPallet->location_id !== null) {
                $locationCode = $stockPallet->location?->code
                    ?? Location::query()->whereKey($stockPallet->location_id)->value('code');

                if ($locationCode !== null) {
                    $stockPallet->location_text = $locationCode;
                }
            }

            $stockPallet->status = in_array((string) $stockPallet->status, self::statuses(), true)
                ? $stockPallet->status
                : self::STATUS_AVAILABLE;
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_AVAILABLE,
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
            self::STATUS_AVAILABLE => 'Disponible',
            self::STATUS_BLOCKED => 'Bloqueado',
            self::STATUS_OBSOLETE => 'Obsoleto',
        ];
    }

    public static function statusLabelFor(?string $status): string
    {
        return self::statusOptions()[$status ?? ''] ?? 'Disponible';
    }

    public function statusLabel(): string
    {
        return self::statusLabelFor($this->status);
    }
}
