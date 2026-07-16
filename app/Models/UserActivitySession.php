<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActivitySession extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
            'active_seconds' => 'integer',
        ];
    }
}
