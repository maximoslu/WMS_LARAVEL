<?php

namespace App\Support\Stock;

use Illuminate\Support\Str;

class LotNormalizer
{
    public const NO_LOT = 'NO LOTE';

    /**
     * Normalize operational batch lots without altering real lot values.
     */
    public static function normalize(mixed $lot): string
    {
        if ($lot === null) {
            return self::NO_LOT;
        }

        $trimmed = trim((string) $lot);

        if ($trimmed === '') {
            return self::NO_LOT;
        }

        return self::isNoLotAlias($trimmed)
            ? self::NO_LOT
            : $trimmed;
    }

    public static function isNoLotAlias(mixed $lot): bool
    {
        if ($lot === null) {
            return true;
        }

        $trimmed = trim((string) $lot);

        if ($trimmed === '') {
            return true;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;
        $comparison = Str::upper($collapsed);

        return in_array($comparison, [self::NO_LOT, 'SIN LOTE'], true);
    }
}
