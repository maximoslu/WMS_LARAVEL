<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyOperationDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'operation_date',
        'client_id',
        'opening_pallets',
        'stored_pallets_today',
        'moved_pallets_today',
        'expected_pallets_tomorrow',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'operation_date' => 'date',
            'opening_pallets' => 'integer',
            'stored_pallets_today' => 'integer',
            'moved_pallets_today' => 'integer',
            'expected_pallets_tomorrow' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DailyOperationLine::class, 'day_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return array<string, int>
     */
    public function sectionTotals(): array
    {
        $lines = $this->relationLoaded('lines')
            ? $this->lines
            : $this->lines()->get();

        return collect(DailyOperationLine::sections())
            ->mapWithKeys(fn (string $section): array => [
                $section => (int) $lines->where('section', $section)->sum('pallets'),
            ])
            ->all();
    }

    public function linesTotal(): int
    {
        $lines = $this->relationLoaded('lines')
            ? $this->lines
            : $this->lines()->get();

        return (int) $lines->sum('pallets');
    }
}
