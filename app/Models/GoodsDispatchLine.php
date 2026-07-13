<?php

namespace App\Models;

use App\Support\WmsLineType;
use Database\Factories\GoodsDispatchLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsDispatchLine extends Model
{
    /** @use HasFactory<GoodsDispatchLineFactory> */
    use HasFactory;

    protected $fillable = [
        'goods_dispatch_id',
        'item_id',
        'stock_pallet_id',
        'line_type',
        'stock_peak_index',
        'sku',
        'description',
        'lot',
        'destination_location',
        'units_per_pallet',
        'units_per_peak',
        'pallets',
        'requested_units',
        'requested_pallets',
        'requested_peaks',
        'loaded_pallets',
        'loaded_peaks',
        'loaded_partial_units',
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
            'stock_pallet_id' => 'integer',
            'stock_peak_index' => 'integer',
            'units_per_pallet' => 'integer',
            'units_per_peak' => 'integer',
            'pallets' => 'integer',
            'requested_units' => 'integer',
            'requested_pallets' => 'integer',
            'requested_peaks' => 'integer',
            'loaded_pallets' => 'integer',
            'loaded_peaks' => 'integer',
            'loaded_partial_units' => 'integer',
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

    public function stockPallet(): BelongsTo
    {
        return $this->belongsTo(StockPallet::class);
    }

    public function sourceRequestLine(): BelongsTo
    {
        return $this->belongsTo(MerchandiseRequestLine::class, 'source_request_line_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(GoodsDispatchLineAllocation::class);
    }

    public function lineType(): string
    {
        return in_array((string) $this->line_type, WmsLineType::values(), true)
            ? (string) $this->line_type
            : WmsLineType::PALLET;
    }

    public function isPalletLine(): bool
    {
        return $this->lineType() === WmsLineType::PALLET;
    }

    public function isPeakLine(): bool
    {
        return $this->lineType() === WmsLineType::PEAK;
    }

    public function lineTypeLabel(): string
    {
        return WmsLineType::label($this->lineType());
    }

    public function requestedPallets(): int
    {
        return $this->isPalletLine()
            ? (int) ($this->requested_pallets ?? $this->pallets ?? 0)
            : 0;
    }

    public function requestedPeaks(): int
    {
        return $this->isPeakLine()
            ? (int) ($this->requested_peaks ?? 0)
            : 0;
    }

    public function loadedPallets(): int
    {
        if ($this->hasLoadingAllocations()) {
            return (int) $this->loadingAllocations()->sum(fn (GoodsDispatchLineAllocation $allocation): int => $allocation->loadedPallets());
        }

        return $this->isPalletLine()
            ? (int) ($this->loaded_pallets ?? $this->requestedPallets())
            : 0;
    }

    public function loadedPeaks(): int
    {
        return $this->isPeakLine()
            ? (int) ($this->loaded_peaks ?? $this->requestedPeaks())
            : 0;
    }

    public function loadedPartialUnits(): int
    {
        if ($this->hasLoadingAllocations()) {
            return (int) $this->loadingAllocations()->sum(fn (GoodsDispatchLineAllocation $allocation): int => $allocation->loadedPartialUnits());
        }

        if ($this->loaded_partial_units !== null) {
            return max(0, (int) $this->loaded_partial_units);
        }

        if ($this->isPeakLine() && $this->loadedPeaks() > 0) {
            return max(0, (int) ($this->units_per_peak ?? 0)) * $this->loadedPeaks();
        }

        return 0;
    }

    public function requestedQuantity(): int
    {
        return $this->isPeakLine()
            ? $this->requestedPeaks()
            : $this->requestedPallets();
    }

    public function requestedUnitsTotal(): int
    {
        if ($this->isPeakLine()) {
            $unitsFromPeaks = $this->requestedPeaks() * max(0, (int) ($this->units_per_peak ?? 0));

            return $unitsFromPeaks > 0 ? $unitsFromPeaks : max(0, (int) $this->requested_units);
        }

        $unitsFromPallets = $this->requestedPallets() * max(0, (int) ($this->units_per_pallet ?? 0));

        return $unitsFromPallets > 0 ? $unitsFromPallets : max(0, (int) $this->requested_units);
    }

    public function loadedQuantity(): int
    {
        return $this->isPeakLine()
            ? $this->loadedPeaks()
            : $this->loadedPallets();
    }

    public function loadedUnitsTotal(): int
    {
        return ($this->loadedPallets() * max(0, (int) ($this->units_per_pallet ?? 0)))
            + $this->loadedPartialUnits();
    }

    public function hasLoadingAllocations(): bool
    {
        if ($this->relationLoaded('allocations')) {
            return $this->allocations->isNotEmpty();
        }

        if (! $this->exists) {
            return false;
        }

        return $this->allocations()->exists();
    }

    /**
     * @return \Illuminate\Support\Collection<int, GoodsDispatchLineAllocation>
     */
    public function loadingAllocations()
    {
        if (! $this->relationLoaded('allocations')) {
            $this->load('allocations');
        }

        return $this->allocations;
    }

    public function requestedQuantityLabel(): string
    {
        $quantity = $this->requestedQuantity();

        return number_format($quantity, 0, ',', '.').' '.WmsLineType::quantityLabel($this->lineType(), $quantity);
    }

    public function loadedQuantityLabel(): string
    {
        if ($this->loadedPartialUnits() > 0) {
            $parts = [];

            if ($this->loadedPallets() > 0) {
                $parts[] = number_format($this->loadedPallets(), 0, ',', '.').' '.WmsLineType::quantityLabel(WmsLineType::PALLET, $this->loadedPallets());
            }

            $parts[] = 'Pico: '.number_format($this->loadedPartialUnits(), 0, ',', '.').' uds';

            return implode(' + ', $parts);
        }

        $quantity = $this->loadedQuantity();

        return number_format($quantity, 0, ',', '.').' '.WmsLineType::quantityLabel($this->lineType(), $quantity);
    }

    public function hasLoadingDifference(): bool
    {
        return $this->requestedUnitsTotal() !== $this->loadedUnitsTotal();
    }

    public function loadingStatus(): string
    {
        $loadedUnits = $this->loadedUnitsTotal();
        $requestedUnits = $this->requestedUnitsTotal();

        if ($loadedUnits <= 0) {
            return 'pending';
        }

        if ($requestedUnits > 0 && $loadedUnits < $requestedUnits) {
            return 'partial';
        }

        if ($requestedUnits > 0 && $loadedUnits > $requestedUnits) {
            return 'superior';
        }

        return 'complete';
    }

    public function loadingStatusLabel(): string
    {
        return match ($this->loadingStatus()) {
            'partial' => 'Parcial',
            'complete' => 'Completo',
            'superior' => 'Carga superior a lo solicitado',
            default => 'Sin preparar',
        };
    }

    public function hasDeliveredQuantity(): bool
    {
        return $this->loadedUnitsTotal() > 0;
    }

    public function lineOriginLabel(): string
    {
        return $this->is_extra_line ? 'Extra' : 'Pedido';
    }

    public function unitsLabel(): string
    {
        if ($this->isPeakLine()) {
            return number_format((int) ($this->units_per_peak ?? 0), 0, ',', '.').' uds';
        }

        return number_format((int) ($this->units_per_pallet ?? 0), 0, ',', '.').' uds/pallet';
    }
}
