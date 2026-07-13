<?php

namespace App\Models;

use App\Support\WmsLineType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchandiseRequestLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchandise_request_id',
        'item_id',
        'stock_pallet_id',
        'line_type',
        'stock_peak_index',
        'lot',
        'destination_location',
        'units_per_pallet',
        'units_per_peak',
        'requested_pallets',
        'requested_peaks',
        'requested_units',
        'prepared_pallets',
        'prepared_peaks',
        'prepared_units',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'stock_pallet_id' => 'integer',
            'stock_peak_index' => 'integer',
            'requested_units' => 'integer',
            'units_per_pallet' => 'integer',
            'units_per_peak' => 'integer',
            'requested_pallets' => 'integer',
            'requested_peaks' => 'integer',
            'prepared_pallets' => 'integer',
            'prepared_peaks' => 'integer',
            'prepared_units' => 'integer',
        ];
    }

    public function merchandiseRequest(): BelongsTo
    {
        return $this->belongsTo(MerchandiseRequest::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stockPallet(): BelongsTo
    {
        return $this->belongsTo(StockPallet::class);
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

    public function requestedPalletsCount(): int
    {
        return $this->isPalletLine()
            ? (int) ($this->requested_pallets ?? 0)
            : 0;
    }

    public function requestedPeaksCount(): int
    {
        return $this->isPeakLine()
            ? (int) ($this->requested_peaks ?? 0)
            : 0;
    }

    public function requestedQuantity(): int
    {
        return $this->isPeakLine()
            ? $this->requestedPeaksCount()
            : $this->requestedPalletsCount();
    }

    public function requestedQuantityLabel(): string
    {
        $quantity = $this->requestedQuantity();

        return number_format($quantity, 0, ',', '.').' '.WmsLineType::quantityLabel($this->lineType(), $quantity);
    }

    public function unitsLabel(): string
    {
        if ($this->isPeakLine()) {
            return number_format((int) ($this->units_per_peak ?? 0), 0, ',', '.').' uds';
        }

        return number_format((int) $this->units_per_pallet, 0, ',', '.').' uds/pallet';
    }
}
