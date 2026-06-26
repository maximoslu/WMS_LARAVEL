<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchandiseRequestLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchandise_request_id',
        'item_id',
        'lot',
        'units_per_pallet',
        'requested_pallets',
        'requested_units',
        'prepared_pallets',
        'prepared_units',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_units' => 'integer',
            'units_per_pallet' => 'integer',
            'requested_pallets' => 'integer',
            'prepared_pallets' => 'integer',
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
}
