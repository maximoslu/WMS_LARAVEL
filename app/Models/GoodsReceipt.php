<?php

namespace App\Models;

use Database\Factories\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class GoodsReceipt extends Model
{
    /** @use HasFactory<GoodsReceiptFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const AI_STATUS_PENDING = 'pending';

    public const AI_STATUS_PROCESSED = 'processed';

    public const AI_STATUS_FAILED = 'failed';

    protected $fillable = [
        'client_id',
        'supplier_id',
        'receipt_number',
        'external_document_number',
        'status',
        'received_at',
        'notes',
        'document_path',
        'document_original_name',
        'document_mime',
        'document_processed_at',
        'ai_status',
        'ai_extracted_data',
        'ai_error',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'stock_applied_at',
        'stock_applied_by',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'document_processed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'stock_applied_at' => 'datetime',
            'ai_extracted_data' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function stockAppliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stock_applied_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function stockPallets(): HasMany
    {
        return $this->hasMany(StockPallet::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function hasStockApplied(): bool
    {
        return $this->stock_applied_at !== null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Borrador',
            self::STATUS_PENDING_REVIEW => 'Pendiente de revision',
            self::STATUS_CONFIRMED => 'Confirmada',
            self::STATUS_CANCELLED => 'Cancelada',
            default => ucfirst((string) $this->status),
        };
    }

    public function aiStatusLabel(): string
    {
        return match ($this->ai_status) {
            self::AI_STATUS_PENDING => 'Pendiente',
            self::AI_STATUS_PROCESSED => 'Procesado',
            self::AI_STATUS_FAILED => 'Fallido',
            null => 'Proximamente',
            default => ucfirst((string) $this->ai_status),
        };
    }

    protected function documentUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->document_path !== null
            ? Storage::disk('public')->url($this->document_path)
            : null);
    }
}
