<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSectionMetric extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'last_seen_at' => 'datetime',
            'visits' => 'integer',
            'active_seconds' => 'integer',
        ];
    }
}
