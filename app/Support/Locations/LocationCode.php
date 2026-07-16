<?php

namespace App\Support\Locations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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

    public static function applyNaturalOrder(Builder $query, string $column = 'code'): Builder
    {
        $wrapped = DB::getQueryGrammar()->wrap($column);
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return $query
                ->orderByRaw("CASE WHEN {$wrapped} GLOB '[0-9]*' AND {$wrapped} NOT GLOB '*[^0-9]*' THEN 0 ELSE 1 END")
                ->orderByRaw("CASE WHEN {$wrapped} GLOB '[0-9]*' AND {$wrapped} NOT GLOB '*[^0-9]*' THEN CAST({$wrapped} AS INTEGER) ELSE NULL END")
                ->orderBy($column);
        }

        return $query
            ->orderByRaw("CASE WHEN {$wrapped} REGEXP '^[0-9]+$' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN {$wrapped} REGEXP '^[0-9]+$' THEN CAST({$wrapped} AS UNSIGNED) ELSE NULL END")
            ->orderBy($column);
    }
}
