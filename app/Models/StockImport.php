<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockImport extends Model
{
    use HasFactory;

    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'client_id',
        'uploaded_by',
        'original_filename',
        'stored_path',
        'status',
        'total_rows',
        'imported_rows',
        'skipped_rows',
        'available_rows',
        'blocked_rows',
        'detected_sheets_json',
        'summary_json',
        'warnings_json',
        'errors_json',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'imported_rows' => 'integer',
            'skipped_rows' => 'integer',
            'available_rows' => 'integer',
            'blocked_rows' => 'integer',
            'detected_sheets_json' => 'array',
            'summary_json' => 'array',
            'warnings_json' => 'array',
            'errors_json' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function stockPallets(): HasMany
    {
        return $this->hasMany(StockPallet::class);
    }

    public static function statusLabelFor(?string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING_CONFIRMATION,
            self::STATUS_PREVIEWED => 'Previsualizada',
            self::STATUS_IMPORTED => 'Importada',
            self::STATUS_FAILED => 'Fallida',
            self::STATUS_IMPORTING => 'Importando',
            default => 'Previsualizada',
        };
    }
}
