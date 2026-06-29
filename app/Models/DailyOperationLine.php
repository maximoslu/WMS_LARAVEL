<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyOperationLine extends Model
{
    use HasFactory;

    public const SECTION_DESCARGA = 'descarga';
    public const SECTION_CARGA = 'carga';
    public const SECTION_GESTION = 'gestion';
    public const SECTION_TRANSPORTE = 'transporte';

    protected $fillable = [
        'day_id',
        'section',
        'counterparty_name',
        'pallets',
        'observations',
        'without_booking',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pallets' => 'integer',
            'without_booking' => 'boolean',
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

    /**
     * @return list<string>
     */
    public static function sections(): array
    {
        return [
            self::SECTION_DESCARGA,
            self::SECTION_CARGA,
            self::SECTION_GESTION,
            self::SECTION_TRANSPORTE,
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
            self::SECTION_GESTION => 'Gestion',
            self::SECTION_TRANSPORTE => 'Transporte',
        ];
    }

    public function sectionLabel(): string
    {
        return self::sectionOptions()[$this->section] ?? ucfirst($this->section);
    }
}
