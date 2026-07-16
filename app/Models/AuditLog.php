<?php

namespace App\Models;

use App\Models\Concerns\ImmutableLedgerRecord;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use ImmutableLedgerRecord;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
