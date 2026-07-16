<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAlertRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'include_blocked_stock' => 'boolean',
            'include_obsolete_stock' => 'boolean',
            'last_evaluated_at' => 'datetime',
            'last_alerted_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(StockAlertEvent::class);
    }
}
