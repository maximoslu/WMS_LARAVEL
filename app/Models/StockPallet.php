<?php

namespace App\Models;

use App\Support\Stock\StockBatchCalculator;
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
        'received_at',
        'status',
        'blocked_reason',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'quantity_units' => 'integer',
            'units_per_pallet' => 'integer',
            'full_pallets' => 'integer',
            'peaks_count' => 'integer',
            'peak_1' => 'integer',
            'peak_2' => 'integer',
            'peak_3' => 'integer',
            'peak_4' => 'integer',
            'peak_5' => 'integer',
            'peak_6' => 'integer',
            'peak_7' => 'integer',
            'peak_8' => 'integer',
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
                    'client_id' => 'El cliente de la partida debe coincidir con el cliente del articulo.',
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

            $stockPallet->pallet_code = filled($stockPallet->pallet_code)
                ? trim((string) $stockPallet->pallet_code)
                : null;

            $stockPallet->status = in_array((string) $stockPallet->status, self::statuses(), true)
                ? $stockPallet->status
                : self::STATUS_AVAILABLE;

            if ((int) $stockPallet->units_per_pallet > 0 && (int) $stockPallet->quantity_units >= 0) {
                $breakdown = StockBatchCalculator::calculateBreakdown(
                    (int) $stockPallet->quantity_units,
                    (int) $stockPallet->units_per_pallet,
                );

                $stockPallet->full_pallets = $breakdown['full_pallets'];
                $stockPallet->peaks_count = $breakdown['peaks_count'];
                $stockPallet->peak_1 = $breakdown['peak_1'];
                $stockPallet->peak_2 = $breakdown['peak_2'];
                $stockPallet->peak_3 = $breakdown['peak_3'];
                $stockPallet->peak_4 = $breakdown['peak_4'];
                $stockPallet->peak_5 = $breakdown['peak_5'];
                $stockPallet->peak_6 = $breakdown['peak_6'];
                $stockPallet->peak_7 = $breakdown['peak_7'];
                $stockPallet->peak_8 = $breakdown['peak_8'];
            }
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
