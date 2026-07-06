<?php

namespace App\Services\GoodsReceipts;

use App\Support\Stock\StockBatchCalculator;

class GoodsReceiptAiExtractionResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private readonly array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $warnings = self::normalizeStringList($data['warnings'] ?? []);

        $lines = collect($data['lines'] ?? [])
            ->map(function (mixed $line, int $index) use (&$warnings): array {
                $line = is_array($line) ? $line : [];

                $sku = self::normalizeNullableUpper($line['sku'] ?? null);
                $description = self::normalizeNullableText($line['description'] ?? null);
                $lot = self::normalizeNullableUpper($line['lot'] ?? null);
                $unitsPerPallet = self::normalizeNullableInteger($line['units_per_pallet'] ?? null);
                $totalUnits = self::normalizeInteger($line['total_units'] ?? 0);
                $fullPallets = self::normalizeNullableInteger($line['full_pallets'] ?? null);
                $peakUnits = self::normalizeNullableInteger($line['peak_units'] ?? null);
                $lineWarnings = self::normalizeStringList($line['warnings'] ?? []);

                if ($unitsPerPallet !== null && $totalUnits > 0) {
                    $computedPallets = StockBatchCalculator::calculateFullPallets($totalUnits, $unitsPerPallet);
                    $computedPeak = StockBatchCalculator::calculateRemainderPeak($totalUnits, $unitsPerPallet);

                    if (($fullPallets !== null && $fullPallets !== $computedPallets)
                        || (($peakUnits ?? 0) !== $computedPeak && $peakUnits !== null)) {
                        $lineWarnings[] = 'La propuesta IA no cuadraba con unidades y paletizado. Se ha recalculado antes de aplicar.';
                    }

                    $fullPallets = $computedPallets;
                    $peakUnits = $computedPeak > 0 ? $computedPeak : null;
                } else {
                    $fullPallets ??= 0;
                    $peakUnits = ($peakUnits ?? 0) > 0 ? $peakUnits : null;
                }

                if ($sku === null && $description === null) {
                    $warnings[] = 'Una de las lineas propuestas por IA venia sin SKU ni descripcion y requiere revision manual.';
                }

                return [
                    'sku' => $sku,
                    'description' => $description,
                    'lot' => $lot,
                    'units_per_pallet' => $unitsPerPallet,
                    'total_units' => $totalUnits,
                    'full_pallets' => $fullPallets ?? 0,
                    'peak_units' => $peakUnits,
                    'confidence' => self::normalizeNullableFloat($line['confidence'] ?? null),
                    'warnings' => array_values(array_unique($lineWarnings)),
                ];
            })
            ->filter(fn (array $line): bool => $line['sku'] !== null
                || $line['description'] !== null
                || $line['lot'] !== null
                || $line['total_units'] > 0
                || $line['units_per_pallet'] !== null)
            ->values()
            ->all();

        return new self([
            'supplier_name' => self::normalizeNullableText($data['supplier_name'] ?? null),
            'matched_supplier_id' => self::normalizeNullableInteger($data['matched_supplier_id'] ?? null),
            'delivery_note_number' => self::normalizeNullableUpper($data['delivery_note_number'] ?? null),
            'received_date' => self::normalizeNullableDate($data['received_date'] ?? null),
            'confidence' => self::normalizeNullableFloat($data['confidence'] ?? null),
            'warnings' => array_values(array_unique($warnings)),
            'lines' => $lines,
            'provider' => self::normalizeNullableText($data['provider'] ?? null),
            'model' => self::normalizeNullableText($data['model'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    private static function normalizeInteger(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private static function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private static function normalizeNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(1, round((float) $value, 4)));
    }

    private static function normalizeNullableUpper(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_strtoupper($normalized);
    }

    private static function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeNullableDate(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $warning): string => trim((string) $warning))
            ->filter(fn (string $warning): bool => $warning !== '')
            ->values()
            ->all();
    }
}
