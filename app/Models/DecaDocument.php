<?php

namespace App\Models;

use App\Models\Concerns\ImmutableLedgerRecord;
use Illuminate\Database\Eloquent\Model;

class DecaDocument extends Model
{
    use ImmutableLedgerRecord;

    protected $guarded = ['id'];

    protected $hidden = ['public_token', 'public_url', 'snapshot', 'pdf_path'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'transport_date' => 'date',
            'issued_at' => 'immutable_datetime',
            'retain_until' => 'immutable_datetime',
        ];
    }
}
