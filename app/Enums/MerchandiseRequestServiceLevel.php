<?php

namespace App\Enums;

enum MerchandiseRequestServiceLevel: string
{
    case SAME_DAY = 'same_day';
    case STANDARD_24H = 'standard_24h';

    public function label(): string
    {
        return match ($this) {
            self::SAME_DAY => 'Para hoy',
            self::STANDARD_24H => 'Cauce normal',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SAME_DAY => 'Necesito la entrega durante el día de hoy.',
            self::STANDARD_24H => 'Entrega dentro de las 24 horas siguientes.',
        };
    }

    public function emailSubjectLabel(): string
    {
        return match ($this) {
            self::SAME_DAY => 'PARA HOY',
            self::STANDARD_24H => 'CAUCE NORMAL (24 H)',
        };
    }
}
