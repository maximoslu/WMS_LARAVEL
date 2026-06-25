<?php

namespace App\Models;

use Database\Factories\StockPalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class StockPallet extends Model
{
    /** @use HasFactory<StockPalletFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'item_id',
        'location_text',
        'pallet_code',
        'quantity_units',
        'received_at',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'quantity_units' => 'integer',
            'received_at' => 'date',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $stockPallet): void {
            $itemClientId = $stockPallet->item?->client_id
                ?? Item::query()->whereKey($stockPallet->item_id)->value('client_id');

            if ($itemClientId === null) {
                throw ValidationException::withMessages([
                    'item_id' => 'El articulo seleccionado no existe.',
                ]);
            }

            if ($stockPallet->client_id !== null && (int) $stockPallet->client_id !== (int) $itemClientId) {
                throw ValidationException::withMessages([
                    'client_id' => 'El cliente del palet debe coincidir con el cliente del articulo.',
                ]);
            }

            $stockPallet->client_id = (int) $itemClientId;
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
