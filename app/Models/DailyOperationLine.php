<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyOperationLine extends Model
{
    use HasFactory;

    public const SECTION_DESCARGA = 'descarga';
    public const SECTION_CARGA = 'carga';
    public const SECTION_ENVIO = 'envio';
    public const SECTION_HORAS_OPERARIO = 'horas_operario';
    public const SECTION_GESTION_CAMION = 'gestion_camion';
    public const SECTION_VIAJE_CAMION = 'viaje_camion';
    public const SECTION_ALMACENAJE = 'almacenaje';
    public const SECTION_GESTION = 'gestion';
    public const SECTION_TRANSPORTE = 'transporte';

    public const SOURCE_MANUAL_LINE = 'manual_line';
    public const SOURCE_GOODS_RECEIPT = 'goods_receipt';
    public const SOURCE_GOODS_DISPATCH = 'goods_dispatch';
    public const SOURCE_STOCK_SNAPSHOT = 'stock_snapshot';

    protected $fillable = [
        'day_id',
        'section',
        'counterparty_name',
        'pallets',
        'observations',
        'without_booking',
        'is_auto_generated',
        'source_type',
        'source_id',
        'parent_line_id',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pallets' => 'integer',
            'without_booking' => 'boolean',
            'is_auto_generated' => 'boolean',
            'source_id' => 'integer',
            'parent_line_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(DailyOperationDay::class, 'day_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parentLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_line_id');
    }

    public function childLines(): HasMany
    {
        return $this->hasMany(self::class, 'parent_line_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return list<string>
     */
    public static function sections(): array
    {
        return [
            self::SECTION_DESCARGA,
            self::SECTION_CARGA,
            self::SECTION_ENVIO,
            self::SECTION_HORAS_OPERARIO,
            self::SECTION_GESTION_CAMION,
            self::SECTION_VIAJE_CAMION,
            self::SECTION_ALMACENAJE,
            self::SECTION_TRANSPORTE,
            self::SECTION_GESTION,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sectionOptions(): array
    {
        return [
            self::SECTION_DESCARGA => 'Descarga',
            self::SECTION_CARGA => 'Carga',
            self::SECTION_ENVIO => 'Envío',
            self::SECTION_HORAS_OPERARIO => 'Horas operario',
            self::SECTION_GESTION_CAMION => 'Gestión de camión',
            self::SECTION_VIAJE_CAMION => 'Viaje de camión',
            self::SECTION_ALMACENAJE => 'Almacenaje',
            self::SECTION_TRANSPORTE => 'Transporte',
            self::SECTION_GESTION => 'Gestión',
        ];
    }

    public function sectionLabel(): string
    {
        return self::sectionOptions()[$this->section] ?? ucfirst($this->section);
    }

    /**
     * @return list<string>
     */
    public static function movementInboundSections(): array
    {
        return [
            self::SECTION_DESCARGA,
        ];
    }

    /**
     * @return list<string>
     */
    public static function movementOutboundSections(): array
    {
        return [
            self::SECTION_CARGA,
            self::SECTION_ENVIO,
        ];
    }

    /**
     * @return list<string>
     */
    public static function sectionsThatRequireTruckManagement(): array
    {
        return [
            self::SECTION_DESCARGA,
            self::SECTION_CARGA,
            self::SECTION_ENVIO,
            self::SECTION_VIAJE_CAMION,
        ];
    }

    /**
     * @return list<string>
     */
    public static function sectionsThatRequireTruckTrip(): array
    {
        return [
            self::SECTION_ENVIO,
        ];
    }

    public function requiresTruckManagement(): bool
    {
        return in_array($this->section, self::sectionsThatRequireTruckManagement(), true);
    }

    public function requiresTruckTrip(): bool
    {
        return in_array($this->section, self::sectionsThatRequireTruckTrip(), true);
    }

    public function contributesToInboundMovement(): bool
    {
        return in_array($this->section, self::movementInboundSections(), true);
    }

    public function contributesToOutboundMovement(): bool
    {
        return in_array($this->section, self::movementOutboundSections(), true);
    }

    public function canBeManuallyManaged(): bool
    {
        return ! $this->is_auto_generated || $this->source_type === self::SOURCE_MANUAL_LINE;
    }
}
