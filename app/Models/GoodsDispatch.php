<?php

namespace App\Models;

use App\Support\WmsStatus;
use Database\Factories\GoodsDispatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsDispatch extends Model
{
    /** @use HasFactory<GoodsDispatchFactory> */
    use HasFactory;

    public const TYPE_REQUEST = 'request';

    public const TYPE_MANUAL = 'manual';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_SENT = 'sent';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'dispatch_number',
        'client_id',
        'merchandise_request_id',
        'type',
        'status',
        'created_by',
        'sent_at',
        'completed_at',
        'delivery_note_sent_at',
        'stock_applied_at',
        'stock_applied_by',
        'warehouse_stock_applied_at',
        'warehouse_stock_applied_by',
        'notes',
        'camion_propio',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'delivery_note_sent_at' => 'datetime',
            'stock_applied_at' => 'datetime',
            'warehouse_stock_applied_at' => 'datetime',
            'camion_propio' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $dispatch): void {
            if (filled($dispatch->dispatch_number)) {
                return;
            }

            $dispatch->forceFill([
                'dispatch_number' => 'SAL-'.str_pad((string) $dispatch->id, 6, '0', STR_PAD_LEFT),
            ])->saveQuietly();
        });
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PREPARING,
            self::STATUS_SENT,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function merchandiseRequest(): BelongsTo
    {
        return $this->belongsTo(MerchandiseRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockAppliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stock_applied_by');
    }

    public function warehouseStockAppliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'warehouse_stock_applied_by');
    }

    public function hasStockApplied(): bool
    {
        return $this->stock_applied_at !== null;
    }

    public function hasWarehouseStockApplied(): bool
    {
        return $this->warehouse_stock_applied_at !== null;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsDispatchLine::class);
    }

    public function dispatchNumber(): string
    {
        return $this->dispatch_number ?: 'SAL-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function palletsCount(): int
    {
        return $this->relationLoaded('lines')
            ? (int) $this->lines->sum(fn (GoodsDispatchLine $line) => $line->requestedPallets())
            : (int) $this->lines()->sum('requested_pallets');
    }

    public function peaksCount(): int
    {
        return $this->relationLoaded('lines')
            ? (int) $this->lines->sum(fn (GoodsDispatchLine $line) => $line->requestedPeaks())
            : (int) $this->lines()->sum('requested_peaks');
    }

    public function loadedPalletsCount(): int
    {
        return $this->relationLoaded('lines')
            ? (int) $this->lines->sum(fn (GoodsDispatchLine $line) => $line->loadedPallets())
            : (int) $this->lines()->sum('loaded_pallets');
    }

    public function loadedPeaksCount(): int
    {
        return $this->relationLoaded('lines')
            ? (int) $this->lines->sum(fn (GoodsDispatchLine $line) => $line->loadedPeaks())
            : (int) $this->lines()->sum('loaded_peaks');
    }

    public function requestedUnitsCount(): int
    {
        if (! $this->relationLoaded('lines')) {
            $this->load('lines');
        }

        return (int) $this->lines->sum(fn (GoodsDispatchLine $line) => $line->requestedUnitsTotal());
    }

    public function loadedUnitsCount(): int
    {
        if (! $this->relationLoaded('lines')) {
            $this->load('lines');
        }

        return (int) $this->lines->sum(fn (GoodsDispatchLine $line) => $line->loadedUnitsTotal());
    }

    public function hasLoadingDifferences(): bool
    {
        if (! $this->relationLoaded('lines')) {
            $this->load('lines');
        }

        return $this->lines->contains(
            fn (GoodsDispatchLine $line) => $line->is_extra_line || $line->hasLoadingDifference()
        );
    }

    public function hasDeliveredLine(): bool
    {
        if (! $this->relationLoaded('lines')) {
            $this->load('lines');
        }

        return $this->lines->contains(fn (GoodsDispatchLine $line) => $line->hasDeliveredQuantity());
    }

    public function hasConfirmedLoading(): bool
    {
        if (! $this->relationLoaded('lines')) {
            $this->load('lines');
        }

        return $this->lines->isNotEmpty()
            && $this->lines->every(fn (GoodsDispatchLine $line) => $line->confirmed_at !== null);
    }

    public function latestLoadingConfirmationAt()
    {
        if (! $this->relationLoaded('lines')) {
            $this->load('lines');
        }

        return $this->lines->max('confirmed_at');
    }

    public function latestLoadingConfirmedBy(): ?int
    {
        if (! $this->relationLoaded('lines')) {
            $this->load('lines');
        }

        return $this->lines->sortByDesc('confirmed_at')->first()?->confirmed_by;
    }

    public function statusLabel(): string
    {
        return WmsStatus::goodsDispatchLabel((string) $this->status);
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return WmsStatus::goodsDispatchLabels();
    }
}
