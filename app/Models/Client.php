<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'delivery_address',
        'delivery_postal_code',
        'delivery_city',
        'delivery_province',
        'delivery_country',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function stockPallets(): HasMany
    {
        return $this->hasMany(StockPallet::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function accessRequests(): HasMany
    {
        return $this->hasMany(AccessRequest::class);
    }

    public function merchandiseRequests(): HasMany
    {
        return $this->hasMany(MerchandiseRequest::class);
    }

    public function goodsDispatches(): HasMany
    {
        return $this->hasMany(GoodsDispatch::class);
    }

    public function formattedDeliveryAddress(): string
    {
        $lines = collect([
            $this->delivery_address,
            trim(collect([
                $this->delivery_postal_code,
                $this->delivery_city,
                $this->delivery_province,
            ])->filter()->implode(' ')),
            $this->delivery_country,
        ])->filter(fn (?string $value) => filled($value));

        return $lines->implode(', ');
    }
}
