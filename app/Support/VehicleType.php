<?php

namespace App\Support;

use App\Models\Client;
use Illuminate\Validation\ValidationException;

class VehicleType
{
    public const OPTIONS = ['rigid' => 'Camión rígido', 'trailer' => 'Tráiler'];

    public static function label(?string $type): string
    {
        return self::OPTIONS[$type] ?? 'Sin clasificar';
    }

    public static function requireForClient(?Client $client, ?string $type): void
    {
        if ($client?->requiresVehicleType() && ! array_key_exists($type ?? '', self::OPTIONS)) {
            throw ValidationException::withMessages([
                'vehicle_type' => 'TI Automotive: indica camión rígido o tráiler antes de confirmar el movimiento.',
            ]);
        }
    }
}
