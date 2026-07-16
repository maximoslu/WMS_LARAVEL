<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAlertEvent extends Model
{
    public const STATUS_WARNING = 'warning';

    public const STATUS_CRITICAL = 'critical';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_SILENCED = 'silenced';

    public const STATUS_RESOLVED = 'resolved';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'recipients' => 'array',
            'coverage_days' => 'decimal:2',
            'estimated_exhaustion_date' => 'date',
            'triggered_at' => 'datetime',
            'notified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'silenced_until' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(StockAlertRule::class, 'stock_alert_rule_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
