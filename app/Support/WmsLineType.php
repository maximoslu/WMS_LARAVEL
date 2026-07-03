<?php

namespace App\Support;

final class WmsLineType
{
    public const PALLET = 'pallet';

    public const PEAK = 'peak';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::PALLET,
            self::PEAK,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::PALLET => 'Pallet completo',
            self::PEAK => 'Pico',
        ];
    }

    public static function label(?string $lineType): string
    {
        return self::labels()[$lineType ?? ''] ?? self::labels()[self::PALLET];
    }

    public static function quantityLabel(?string $lineType, int $quantity = 1): string
    {
        return match ($lineType) {
            self::PEAK => $quantity === 1 ? 'pico' : 'picos',
            default => $quantity === 1 ? 'pallet' : 'pallets',
        };
    }
}
