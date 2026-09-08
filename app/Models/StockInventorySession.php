<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockInventorySession extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'uuid',
        'client_id',
        'status',
        'open_slot',
        'scope_filters',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'final_summary',
    ];

    protected function casts(): array
    {
        return [
            'open_slot' => 'boolean',
            'scope_filters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'final_summary' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(StockInventoryLocation::class);
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }
}
