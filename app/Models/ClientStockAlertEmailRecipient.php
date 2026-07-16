<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientStockAlertEmailRecipient extends Model
{
    protected $fillable = ['client_id', 'email', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
