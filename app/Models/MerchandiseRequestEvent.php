<?php

namespace App\Models;

use Database\Factories\MerchandiseRequestEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchandiseRequestEvent extends Model
{
    /** @use HasFactory<MerchandiseRequestEventFactory> */
    use HasFactory;

    protected $fillable = [
        'merchandise_request_id',
        'user_id',
        'event_type',
        'title',
        'description',
    ];

    public function merchandiseRequest(): BelongsTo
    {
        return $this->belongsTo(MerchandiseRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
