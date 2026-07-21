<?php

namespace App\Console\Commands;

use App\Models\Warehouse;
use App\Services\Locations\LocationCatalogService;
use App\Services\Locations\LocationPurgeService;
use Illuminate\Console\Command;

class CreateLocationRangeCommand extends Command
{
    protected $signature = 'wms:locations:create-range
        {--warehouse= : Codigo, nombre o ID del almacen}
        {--type=Calle : Calle, Pasillo, Estanteria, Muelle, Zona o Libre}
        {--from= : Valor inicial numerico}
        {--to= : Valor final numerico}
        {--confirm= : Confirmacion textual para rangos grandes}
        {--dry-run : Muestra el plan sin modificar datos}
        {--apply : Crea las ubicaciones}';

    protected $description = 'Crea rangos masivos de ubicaciones sin duplicar codigos fisicos.';

    public function handle(LocationPurgeService $purge, LocationCatalogService $catalog): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Usa --dry-run o --apply, no ambos.');

            return self::FAILURE;
        }

        $warehouse = $purge->resolveWarehouse($this->option('warehouse'));
        if (! $warehouse instanceof Warehouse) {
            $this->error('Indica un almacen valido con --warehouse.');

            return self::FAILURE;
        }

        $from = $this->numericOption('from');
        $to = $this->numericOption('to');

        if ($from === null || $to === null || $from < 0 || $to < 0) {
            $this->error('Indica --from y --to como enteros positivos o cero.');

            return self::FAILURE;
        }

        if ($from > $to) {
            $this->error('El valor --to debe ser mayor o igual que --from.');

            return self::FAILURE;
        }

        $count = $to - $from + 1;

        if ($this->option('apply') && $count > 10000 && $this->option('confirm') !== 'CREAR RANGO '.$count) {
            $this->error('Para aplicar este rango escribe --confirm="CREAR RANGO '.$count.'".');

            return self::FAILURE;
        }

        if ($this->option('apply') && $count > 1000 && $count <= 10000 && $this->option('confirm') !== 'CREAR RANGO') {
            $this->error('Para aplicar rangos de mas de 1000 ubicaciones escribe --confirm="CREAR RANGO".');

            return self::FAILURE;
        }

        $result = $catalog->createRange(
            warehouseId: $warehouse->id,
            type: (string) $this->option('type'),
            from: $from,
            to: $to,
            apply: (bool) $this->option('apply'),
        );

        $this->info(($this->option('apply') ? 'APPLY' : 'DRY-RUN').' wms:locations:create-range');
        $this->line('Almacen: '.$warehouse->code.' / '.$warehouse->name);
        $this->line('Tipo: '.$catalog->typeOptions()[$catalog->normalizeType($this->option('type'))]);
        $this->line('Rango: '.$from.'-'.$to.' ('.$count.' ubicaciones)');
        $this->line('Creadas: '.$result['created']);
        $this->line('Ya existentes: '.$result['existing']);
        $this->line('Errores: '.$result['errors']);

        if (! $this->option('apply')) {
            $this->warn('Dry-run: no se ha modificado ningun dato. Para aplicar, ejecuta con --apply.');
        }

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function numericOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : null;
    }
}
