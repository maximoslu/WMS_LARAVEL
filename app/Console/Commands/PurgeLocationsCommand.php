<?php

namespace App\Console\Commands;

use App\Models\Warehouse;
use App\Services\Locations\LocationPurgeService;
use Illuminate\Console\Command;

class PurgeLocationsCommand extends Command
{
    protected $signature = 'wms:locations:purge
        {--warehouse= : Codigo, nombre o ID del almacen}
        {--dry-run : Muestra el impacto sin modificar datos}
        {--apply : Aplica la purga controlada}';

    protected $description = 'Purga el catalogo de ubicaciones limpiando referencias operativas sin borrar stock.';

    public function handle(LocationPurgeService $purge): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Usa --dry-run o --apply, no ambos.');

            return self::FAILURE;
        }

        $warehouseFilter = $this->option('warehouse');
        $warehouse = $purge->resolveWarehouse($warehouseFilter);

        if ($warehouseFilter !== null && ! $warehouse instanceof Warehouse) {
            $this->error('No se ha encontrado el almacen indicado. No se ha modificado nada.');

            return self::FAILURE;
        }

        $result = $this->option('apply')
            ? $purge->apply($warehouse)
            : $purge->plan($warehouse);

        $this->info(($this->option('apply') ? 'APPLY' : 'DRY-RUN').' wms:locations:purge');
        $this->line('Alcance: '.($warehouse instanceof Warehouse ? ($warehouse->code.' / '.$warehouse->name) : 'Todos los almacenes'));
        $this->line('Ubicaciones a eliminar: '.$result['locations']);
        $this->line('Partidas de stock que quedarian sin ubicacion: '.$result['stock']);
        $this->line('Textos de ubicacion de stock a limpiar: '.$result['stock_text']);
        $this->line('Articulos con ubicacion por defecto a limpiar: '.$result['items']);
        $this->line('Lineas de entrada a desvincular: '.$result['receipt_lines']);
        $this->line('Movimientos historicos intactos: '.$result['movements']);

        foreach ($result['warehouses'] as $affectedWarehouse) {
            $this->line(' - '.$affectedWarehouse->code.' / '.$affectedWarehouse->name);
        }

        if (! $this->option('apply')) {
            $this->warn('Dry-run: no se ha modificado ningun dato. Para aplicar, ejecuta con --apply.');

            return self::SUCCESS;
        }

        $this->info('Purga completada. Ubicaciones eliminadas: '.$result['deleted']);

        return self::SUCCESS;
    }
}
