<?php

namespace App\Support\Locations;

final class LocationCode
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

    /** @return list<string> */
    public static function expectedEdelvivesCodes(): array
    {
        return [
            ...array_map(static fn (int $code): string => (string) $code, range(0, 45)),
            ...range('A', 'F'),
        ];
    }

    /** @return array{0: int, 1: int, 2: string} */
    public static function naturalSortKey(mixed $value): array
    {
        $code = self::normalize($value);

        if ($code !== '' && ctype_digit($code)) {
            return [0, (int) $code, ''];
        }

        $letterIndex = array_search($code, range('A', 'F'), true);

        if ($letterIndex !== false) {
            return [1, $letterIndex, ''];
        }

        return [2, 0, $code];
    }
}
