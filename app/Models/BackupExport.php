<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupExport extends Model
{
    use HasFactory;

    public const TYPE_FULL_SYSTEM = 'full-system';
    public const TYPE_DATABASE = 'database';
    public const TYPE_MOVEMENTS = 'movements';
    public const TYPE_OPERATIONS = 'operations';
    public const TYPE_STOCK = 'stock';
    public const TYPE_STOCK_CLIENT = 'stock-client';
    public const TYPE_STOCK_SNAPSHOT_DAILY = 'stock_snapshot_daily';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'scope',
        'client_id',
        'status',
        'disk',
        'path',
        'filename',
        'mime_type',
        'size_bytes',
        'checksum',
        'started_at',
        'finished_at',
        'created_by',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? ucfirst(str_replace(['-', '_'], ' ', (string) $this->type));
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? ucfirst((string) $this->status);
    }

    public function formattedSize(): string
    {
        if ($this->size_bytes === null) {
            return '-';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->size_bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return number_format($size, $unit === 0 ? 0 : 2, ',', '.').' '.$units[$unit];
    }

    /**
     * @return array<string, string>
     */
    public static function manualTypeLabels(): array
    {
        return [
            self::TYPE_FULL_SYSTEM => 'Sistema completo',
            self::TYPE_DATABASE => 'Base de datos completa',
            self::TYPE_MOVEMENTS => 'Movimientos',
            self::TYPE_OPERATIONS => 'Operaciones',
            self::TYPE_STOCK => 'Stock completo',
            self::TYPE_STOCK_CLIENT => 'Stock por cliente',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return self::manualTypeLabels() + [
            self::TYPE_STOCK_SNAPSHOT_DAILY => 'Snapshot diario de stock',
        ];
    }

    /**
     * @return list<string>
     */
    public static function manualTypes(): array
    {
        return array_keys(self::manualTypeLabels());
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_RUNNING => 'Ejecutando',
            self::STATUS_COMPLETED => 'Completado',
            self::STATUS_FAILED => 'Fallido',
        ];
    }
}
