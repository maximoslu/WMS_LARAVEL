<?php

namespace App\Support\Warehouses;

final class WarehouseCode
{
    public static function normalize(mixed $value): string
    {
        $code = mb_strtoupper(trim((string) $value));
        $code = preg_replace('/\s+/u', ' ', $code) ?? $code;

        if ($code !== '' && ctype_digit($code)) {
            return (string) ((int) $code);
        }

        return $code;
    }

    /** @return array{0: int, 1: int, 2: string} */
    public static function naturalSortKey(mixed $value): array
    {
        $code = self::normalize($value);

        if ($code !== '' && ctype_digit($code)) {
            return [0, (int) $code, ''];
        }

        return [1, 0, $code];
    }
}
