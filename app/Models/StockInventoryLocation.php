<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockInventoryLocation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CHECKED = 'checked';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'stock_inventory_session_id',
        'client_id',
        'location_id',
        'warehouse_id',
        'scope_key',
        'warehouse_code',
        'warehouse_name',
        'location_code',
        'location_label',
        'check_state',
        'checked_by',
        'checked_at',
        'checked_movement_id',
        'checked_snapshot',
        'notes',
        'finalized_status',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'checked_movement_id' => 'integer',
            'checked_snapshot' => 'array',
        ];
    }

    public function inventorySession(): BelongsTo
    {
        return $this->belongsTo(StockInventorySession::class, 'stock_inventory_session_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
