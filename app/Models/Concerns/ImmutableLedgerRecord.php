<?php

namespace App\Models\Concerns;

use LogicException;

trait ImmutableLedgerRecord
{
    protected static function bootImmutableLedgerRecord(): void
    {
        static::updating(function (): never {
            throw new LogicException('Los registros historicos son inmutables. Registra una correccion o reversion.');
        });

        static::deleting(function (): never {
            throw new LogicException('Los registros historicos no se pueden eliminar.');
        });
    }
}
