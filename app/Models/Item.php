<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'sku',
        'description',
        'lot',
        'lot_key',
        'units_per_pallet',
        'active',
    ];

    protected $hidden = [
        'lot_key',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'units_per_pallet' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
