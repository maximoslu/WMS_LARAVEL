<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Location;
use App\Services\Locations\LocationIntegrityService;
use App\Support\Locations\LocationCode;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AuditLocationsCommand extends Command
{
    protected $signature = 'wms:locations:audit
        {--client= : Codigo, nombre o ID del cliente}
        {--warehouse= : Codigo, nombre o ID del almacen}';

    protected $description = 'Audita ubicaciones, referencias y stock sin modificar datos';

    public function handle(LocationIntegrityService $integrity): int
    {
        $client = $integrity->resolveClient($this->option('client'));

        if ($this->option('client') !== null && ! $client instanceof Client) {
            $this->error('No se ha encontrado el cliente indicado.');

            return self::FAILURE;
        }

        $locations = $integrity->locations($client, $this->option('warehouse'));

        if ($locations->isEmpty()) {
            $this->warn('No hay ubicaciones para los filtros indicados.');

            return self::SUCCESS;
        }

        $duplicates = $integrity->duplicateGroups($locations);

        $this->info('AUDITORIA DE SOLO LECTURA. No se modificara ningun dato.');
        $this->line('FK auditadas: stock_pallets.location_id, goods_receipt_lines.location_id, items.default_location_id e inventory_movements location/from/to.');
        $this->line('Salidas, asignaciones, pedidos y operaciones diarias no tienen location_id en el esquema actual.');

        foreach ($duplicates as $group) {
            $this->reportDuplicate($integrity, $group);
        }

        $missingCount = 0;
        $extraCount = 0;

        foreach ($locations->groupBy('warehouse_id') as $warehouseLocations) {
            $status = $integrity->seriesStatus($warehouseLocations, $client);
            $warehouse = $warehouseLocations->first()->warehouse;
            $label = $warehouse?->name ?: $warehouse?->code ?: $warehouseLocations->first()->warehouse_id;
            $missingCount += count($status['missing']);
            $extraCount += count($status['extras']);

            $this->line('Almacen '.$label.' | faltantes: '.($status['missing'] === [] ? 'ninguna' : implode(', ', $status['missing'])));
            $this->line('Almacen '.$label.' | extras (se conservan): '.($status['extras'] === [] ? 'ninguna' : implode(', ', $status['extras'])));
        }

        $stock = $integrity->stockMap($locations, $client);
        $this->newLine();
        $this->line('Mapa logico de stock por ubicacion:');
        $this->table(
            ['Stock ID', 'Item ID', 'SKU', 'Lote', 'Ubicacion ID', 'Codigo', 'Normalizado', 'Unidades', 'Pallets', 'Picos', 'U. logisticas', 'Activo'],
            $stock->map(fn (object $row): array => [
                $row->id,
                $row->item_id,
                $row->sku,
                $row->lot,
                $row->location_id,
                $row->location_code,
                LocationCode::normalize($row->location_code),
                $row->quantity_units,
                $row->full_pallets,
                $row->peaks_count,
                $row->warehouse_pallets,
                $row->active ? 'si' : 'no',
            ])->all(),
        );

        $this->info(sprintf(
            'Resumen: %d grupo(s) duplicado(s), %d faltante(s), %d extra(s), %d partida(s) de stock. Sin cambios.',
            $duplicates->count(),
            $missingCount,
            $extraCount,
            $stock->count(),
        ));

        return self::SUCCESS;
    }

    /** @param Collection<int, Location> $group */
    private function reportDuplicate(LocationIntegrityService $integrity, Collection $group): void
    {
        $canonical = $integrity->canonicalLocation($group);

        $this->newLine();
        $this->line(sprintf(
            'Duplicado %s | canonica propuesta #%d',
            LocationCode::normalize($canonical->code),
            $canonical->id,
        ));
        $this->table(
            ['ID', 'Codigo', 'Accion propuesta', 'Stock', 'Entradas', 'Articulos', 'Movimientos'],
            $group->map(function (Location $location) use ($canonical, $integrity): array {
                $references = $integrity->referenceCounts($location->id);

                return [
                    $location->id,
                    $location->code,
                    $location->id === $canonical->id ? 'CONSERVAR' : 'REASIGNAR Y ELIMINAR',
                    $references['stock'],
                    $references['receipts'],
                    $references['items'],
                    $references['movements'],
                ];
            })->all(),
        );
    }
}
