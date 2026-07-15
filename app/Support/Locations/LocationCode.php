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
}
