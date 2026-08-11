<?php

namespace App\Models;

use App\Support\WmsStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MerchandiseRequest extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIALLY_FULFILLED = 'partially_fulfilled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'client_id',
        'requested_by',
        'status',
        'delivery_reference',
        'delivery_address',
        'camion_propio',
        'requested_date',
        'notes',
        'prepared_by',
        'prepared_at',
        'shipped_by',
        'shipped_at',
        'completed_at',
        'completed_with_shortfall',
        'remainder_closed_at',
        'remainder_closed_by',
        'remainder_close_reason',
        'remainder_close_snapshot',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'prepared_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'completed_with_shortfall' => 'boolean',
            'remainder_closed_at' => 'datetime',
            'remainder_close_snapshot' => 'array',
            'cancelled_at' => 'datetime',
            'camion_propio' => 'boolean',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
            self::STATUS_PREPARING,
            self::STATUS_SENT,
            self::STATUS_PARTIALLY_FULFILLED,
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
        return $this->hasOne(GoodsDispatch::class)->latestOfMany();
    }

    public function goodsDispatches(): HasMany
    {
        return $this->hasMany(GoodsDispatch::class)->orderBy('shipment_sequence')->orderBy('id');
    }

    public function openDispatch(): HasOne
    {
        return $this->hasOne(GoodsDispatch::class)
            ->whereIn('status', [GoodsDispatch::STATUS_DRAFT, GoodsDispatch::STATUS_PREPARING])
            ->latestOfMany();
    }

    public function remainderClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remainder_closed_by');
    }

    public function referenceCode(): string
    {
        $storedCode = $this->getAttribute('request_code');

        if (filled($storedCode)) {
            return (string) $storedCode;
        }

        $prefix = $this->status === self::STATUS_DRAFT ? 'BOR' : 'SOL';

        return $prefix.'-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
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

        return $this->relationLoaded('lines')
            ? (int) $this->lines->sum(fn (MerchandiseRequestLine $line) => $line->requestedPalletsCount())
            : (int) $this->lines()->sum('requested_pallets');
    }

    public function requestedPeaksCount(): int
    {
        return $this->relationLoaded('lines')
            ? (int) $this->lines->sum(fn (MerchandiseRequestLine $line) => $line->requestedPeaksCount())
            : (int) $this->lines()->sum('requested_peaks');
    }

    public function statusLabel(): string
    {
        return WmsStatus::merchandiseRequestLabel((string) $this->status);
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return WmsStatus::merchandiseRequestLabels();
    }
}
