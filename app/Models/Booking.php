<?php

namespace App\Models;

use App\Support\WmsStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    /** @use HasFactory<\Database\Factories\BookingFactory> */
    use HasFactory;

    public const TYPE_ENTRY = 'entrada';
    public const TYPE_EXIT = 'salida';
    public const TYPE_MIXED = 'mixto';
    public const TYPE_OTHER = 'otro';

    public const STATUS_REQUESTED = 'solicitado';
    public const STATUS_APPROVED = 'aprobado';
    public const STATUS_PLANNED = 'planificado';
    public const STATUS_IN_PROGRESS = 'en_curso';
    public const STATUS_COMPLETED = 'completado';
    public const STATUS_CANCELLED = 'cancelado';
    public const STATUS_REJECTED = 'rechazado';

    protected $fillable = [
        'client_id',
        'requested_by',
        'assigned_to',
        'approved_by',
        'cancelled_by',
        'warehouse_id',
        'booking_code',
        'type',
        'status',
        'scheduled_date',
        'scheduled_time_from',
        'scheduled_time_to',
        'contact_name',
        'contact_phone',
        'carrier_name',
        'vehicle_plate',
        'driver_name',
        'pallets_expected',
        'notes',
        'internal_notes',
        'origin_destination',
        'document_reference',
        'loading_dock',
        'google_calendar_event_id',
        'google_calendar_synced_at',
        'google_calendar_sync_error',
        'approved_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'google_calendar_synced_at' => 'datetime',
            'pallets_expected' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $booking): void {
            if (blank($booking->booking_code)) {
                $booking->forceFill([
                    'booking_code' => 'BK-'.str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT),
                ])->saveQuietly();
            }
        });
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_REQUESTED,
            self::STATUS_APPROVED,
            self::STATUS_PLANNED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_REJECTED,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_ENTRY,
            self::TYPE_EXIT,
            self::TYPE_MIXED,
            self::TYPE_OTHER,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return WmsStatus::bookingLabels();
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_ENTRY => 'Entrada',
            self::TYPE_EXIT => 'Salida',
            self::TYPE_MIXED => 'Mixto',
            self::TYPE_OTHER => 'Otro',
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

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function referenceCode(): string
    {
        return (string) ($this->booking_code ?: 'BK-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT));
    }

    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? ucfirst((string) $this->status);
    }

    public function typeLabel(): string
    {
        return self::typeOptions()[$this->type] ?? ucfirst((string) $this->type);
    }

    public function scheduledWindowLabel(): string
    {
        $date = $this->scheduled_date?->format('d/m/Y') ?? 'Sin fecha';

        if ($this->scheduled_time_from === null && $this->scheduled_time_to === null) {
            return $date;
        }

        if ($this->scheduled_time_from !== null && $this->scheduled_time_to !== null) {
            return sprintf('%s · %s - %s', $date, substr($this->scheduled_time_from, 0, 5), substr($this->scheduled_time_to, 0, 5));
        }

        return sprintf('%s · %s', $date, substr((string) ($this->scheduled_time_from ?? $this->scheduled_time_to), 0, 5));
    }

    public function scheduledDateTime(): ?CarbonInterface
    {
        if ($this->scheduled_date === null) {
            return null;
        }

        $date = $this->scheduled_date->copy();

        if ($this->scheduled_time_from !== null) {
            [$hours, $minutes] = array_pad(explode(':', $this->scheduled_time_from), 2, '0');

            return $date->setTime((int) $hours, (int) $minutes);
        }

        return $date->startOfDay();
    }

    public function canClientCancel(): bool
    {
        return in_array($this->status, [self::STATUS_REQUESTED, self::STATUS_APPROVED], true);
    }

    public function googleCalendarSyncState(): string
    {
        if (filled($this->google_calendar_sync_error)) {
            return 'error';
        }

        if ($this->status === self::STATUS_CANCELLED && $this->google_calendar_synced_at !== null) {
            return 'cancelled';
        }

        if (filled($this->google_calendar_event_id) && $this->google_calendar_synced_at !== null) {
            return 'synced';
        }

        return 'pending';
    }

    public function googleCalendarSyncLabel(): string
    {
        return match ($this->googleCalendarSyncState()) {
            'synced' => 'Sincronizado con Google Calendar',
            'cancelled' => 'Cancelado en Google Calendar',
            'error' => 'Error de sincronizacion con Google Calendar',
            default => 'Pendiente de sincronizar',
        };
    }
}
