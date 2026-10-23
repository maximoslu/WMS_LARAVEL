<?php

namespace App\Models;

use Database\Factories\ClientPreparationEmailRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientPreparationEmailRecipient extends Model
{
    /** @use HasFactory<ClientPreparationEmailRecipientFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'email',
        'name',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
