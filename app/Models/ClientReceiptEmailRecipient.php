<?php

namespace App\Models;

use Database\Factories\ClientReceiptEmailRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientReceiptEmailRecipient extends Model
{
    /** @use HasFactory<ClientReceiptEmailRecipientFactory> */
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
