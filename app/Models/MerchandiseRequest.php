<?php

namespace App\Models;

use Database\Factories\MerchandiseRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchandiseRequest extends Model
{
    /** @use HasFactory<MerchandiseRequestFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CREATED = 'created';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'client_id',
        'requested_by',
        'status',
        'delivery_reference',
        'delivery_address',
        'requested_date',
        'notes',
        'prepared_by',
        'prepared_at',
        'shipped_by',
        'shipped_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'prepared_at' => 'datetime',
            'shipped_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MerchandiseRequestLine::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MerchandiseRequestEvent::class)->orderBy('id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Borrador',
            self::STATUS_CREATED => 'Creada',
            self::STATUS_PREPARED => 'Preparada',
            self::STATUS_SHIPPED => 'Enviada',
            self::STATUS_CANCELLED => 'Cancelada',
            default => ucfirst((string) $this->status),
        };
    }

    public function isEditableByClient(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CREATED], true);
    }
}
