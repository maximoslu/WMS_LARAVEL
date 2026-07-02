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

    public const MAX_PEAK_COLUMNS = 10;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_OBSOLETE = 'obsolete';

    protected $fillable = [
        'client_id',
        'item_id',
        'goods_receipt_id',
        'stock_import_id',
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
        'peak_9',
        'peak_10',
        'received_at',
        'imported_at',
        'status',
        'blocked_reason',
        'source_sheet',
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
            'peak_9' => 'integer',
            'peak_10' => 'integer',
            'received_at' => 'date',
            'imported_at' => 'datetime',
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

            $explicitPeaks = self::explicitPeaks($stockPallet);

            if ((int) $stockPallet->units_per_pallet > 0 && (int) $stockPallet->quantity_units >= 0) {
                if ($explicitPeaks !== []) {
                    $peakTotal = array_sum($explicitPeaks);
                    $remainingUnits = max(0, (int) $stockPallet->quantity_units - $peakTotal);

                    $stockPallet->full_pallets = intdiv($remainingUnits, (int) $stockPallet->units_per_pallet);
                    $stockPallet->peaks_count = count(array_filter($explicitPeaks, fn (int $value): bool => $value > 0));

                    foreach (range(1, self::MAX_PEAK_COLUMNS) as $peakNumber) {
                        $stockPallet->{'peak_'.$peakNumber} = $explicitPeaks[$peakNumber] ?? 0;
                    }
                } else {
                    $breakdown = StockBatchCalculator::calculateBreakdown(
                        (int) $stockPallet->quantity_units,
                        (int) $stockPallet->units_per_pallet,
                    );

                    $stockPallet->full_pallets = $breakdown['full_pallets'];
                    $stockPallet->peaks_count = $breakdown['peaks_count'];

                    foreach (range(1, self::MAX_PEAK_COLUMNS) as $peakNumber) {
                        $stockPallet->{'peak_'.$peakNumber} = $breakdown['peak_'.$peakNumber] ?? 0;
                    }
                }
            } elseif (
                $explicitPeaks !== []
                || ($stockPallet->stock_import_id !== null && ((int) $stockPallet->full_pallets > 0 || (int) $stockPallet->quantity_units > 0))
            ) {
                $stockPallet->full_pallets = max(0, (int) $stockPallet->full_pallets);
                $stockPallet->peaks_count = count(array_filter($explicitPeaks, fn (int $value): bool => $value > 0));

                foreach (range(1, self::MAX_PEAK_COLUMNS) as $peakNumber) {
                    $stockPallet->{'peak_'.$peakNumber} = $explicitPeaks[$peakNumber] ?? 0;
                }
            } else {
                $stockPallet->full_pallets = 0;
                $stockPallet->peaks_count = 0;

                foreach (range(1, self::MAX_PEAK_COLUMNS) as $peakNumber) {
                    $stockPallet->{'peak_'.$peakNumber} = 0;
                }
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

    public function stockImport(): BelongsTo
    {
        return $this->belongsTo(StockImport::class);
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

    /**
     * @return array<int, int>
     */
    private static function explicitPeaks(self $stockPallet): array
    {
        $peaks = [];

        foreach (range(1, self::MAX_PEAK_COLUMNS) as $peakNumber) {
            $value = max(0, (int) ($stockPallet->{'peak_'.$peakNumber} ?? 0));

            if ($value > 0) {
                $peaks[$peakNumber] = $value;
            }
        }

        return $peaks;
    }
}
