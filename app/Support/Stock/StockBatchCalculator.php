<?php

namespace App\Support\Stock;

use InvalidArgumentException;

class StockBatchCalculator
{
    public const MAX_PEAK_COLUMNS = 10;

    public static function calculateFullPallets(int $totalUnits, int $unitsPerPallet): int
    {
        self::guard($totalUnits, $unitsPerPallet);

        return intdiv($totalUnits, $unitsPerPallet);
    }

    public static function calculateRemainderPeak(int $totalUnits, int $unitsPerPallet): int
    {
        self::guard($totalUnits, $unitsPerPallet);

        return $totalUnits % $unitsPerPallet;
    }

    /**
     * @return array<int, int>
     */
    public static function calculatePeaks(int $totalUnits, int $unitsPerPallet): array
    {
        $remainder = self::calculateRemainderPeak($totalUnits, $unitsPerPallet);
        $peaks = array_fill(0, self::MAX_PEAK_COLUMNS, 0);

        if ($remainder > 0) {
            $peaks[0] = $remainder;
        }

        return $peaks;
    }

    /**
     * @return array{
     *     full_pallets: int,
     *     remainder_units: int,
     *     peaks_count: int,
     *     peak_1: int,
     *     peak_2: int,
     *     peak_3: int,
     *     peak_4: int,
     *     peak_5: int,
     *     peak_6: int,
     *     peak_7: int,
     *     peak_8: int,
     *     peak_9: int,
     *     peak_10: int
     * }
     */
    public static function calculateBreakdown(int $totalUnits, int $unitsPerPallet): array
    {
        $fullPallets = self::calculateFullPallets($totalUnits, $unitsPerPallet);
        $peaks = self::calculatePeaks($totalUnits, $unitsPerPallet);

        return [
            'full_pallets' => $fullPallets,
            'remainder_units' => $peaks[0],
            'peaks_count' => $peaks[0] > 0 ? 1 : 0,
            'peak_1' => $peaks[0],
            'peak_2' => $peaks[1],
            'peak_3' => $peaks[2],
            'peak_4' => $peaks[3],
            'peak_5' => $peaks[4],
            'peak_6' => $peaks[5],
            'peak_7' => $peaks[6],
            'peak_8' => $peaks[7],
            'peak_9' => $peaks[8],
            'peak_10' => $peaks[9],
        ];
    }

    private static function guard(int $totalUnits, int $unitsPerPallet): void
    {
        if ($totalUnits < 0) {
            throw new InvalidArgumentException('La cantidad total no puede ser negativa.');
        }

        if ($unitsPerPallet <= 0) {
            throw new InvalidArgumentException('Las unidades por pallet deben ser mayores que cero.');
        }
    }
}
