<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MerchandiseRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_SENT = 'sent';

    public const STATUS_COMPLETED = 'completed';

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

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PREPARING,
            self::STATUS_SENT,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MerchandiseRequestLine::class);
    }

    public function dispatch(): HasOne
    {
        return $this->hasOne(GoodsDispatch::class);
    }

    public function referenceCode(): string
    {
        $storedCode = $this->getAttribute('request_code');

        if (filled($storedCode)) {
            return (string) $storedCode;
        }

        return 'SOL-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function submittedAt(): ?CarbonInterface
    {
        $submittedAt = $this->getAttribute('submitted_at');

        if ($submittedAt instanceof CarbonInterface) {
            return $submittedAt;
        }

        return $this->created_at;
    }

    public function requestedPalletsCount(): int
    {
        $storedTotal = $this->getAttribute('total_pallets');

        if (is_numeric($storedTotal)) {
            return (int) $storedTotal;
        }

        if ($this->relationLoaded('lines')) {
            return (int) $this->lines->sum('requested_pallets');
        }

        return (int) $this->lines()->sum('requested_pallets');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_PREPARING => 'Preparando',
            self::STATUS_SENT => 'Enviado',
            self::STATUS_COMPLETED => 'Completado',
            self::STATUS_CANCELLED => 'Cancelado',
            default => ucfirst((string) $this->status),
        };
    }
}
