<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\StockPallet;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Locations\LocationIntegrityService;
use App\Services\Warehouses\WarehouseIntegrityService;
use App\Support\Locations\LocationCode;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeduplicateWarehousesCommand extends Command
{
    protected $signature = 'wms:warehouses:deduplicate
        {--client= : Codigo, nombre o ID del cliente}
        {--warehouse-code= : Codigo del almacen a consolidar}
        {--dry-run : Muestra el plan sin modificar datos}
        {--apply : Consolida almacenes, ubicaciones y referencias}';

    protected $description = 'Consolida almacenes equivalentes sin perder ubicaciones, stock ni historico';

    public function handle(
        WarehouseIntegrityService $integrity,
        InventoryMovementService $movements,
        AuditLogService $audit,
    ): int {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Usa --dry-run o --apply, no ambos a la vez.');

            return self::FAILURE;
        }

        $client = $integrity->resolveClient($this->option('client'));

        if ($this->option('client') !== null && ! $client instanceof Client) {
            $this->error('No se ha encontrado el cliente indicado. No se ha modificado nada.');

            return self::FAILURE;
        }

        $warehouseCode = (string) ($this->option('warehouse-code') ?: '');
        $warehouses = $integrity->candidateWarehouses($client, $warehouseCode);

        if ($warehouses->isEmpty()) {
            $this->warn('No hay almacenes para los filtros indicados. No se ha modificado ningun dato.');

            return self::SUCCESS;
        }

        $locations = $integrity->locationsForWarehouses($warehouses);
        $duplicateLocationGroups = $integrity->duplicateLocationGroupsAcrossWarehouses($locations);
        $missingCodes = $this->reportPlan($integrity, $warehouses, $locations, $duplicateLocationGroups, $client);

        if (! $this->option('apply')) {
            $this->warn(sprintf(
                'Dry-run: %d almacen(es), %d grupo(s) de ubicacion duplicada, %d ubicacion(es) por crear. No se ha modificado ningun dato.',
                $warehouses->count(),
                $duplicateLocationGroups->count(),
                count($missingCodes),
            ));

            return self::SUCCESS;
        }

        try {
            $correlationId = $audit->correlationId();
            DB::transaction(function () use ($integrity, $movements, $audit, $warehouses, $client, $warehouseCode, $correlationId): void {
                $lockedWarehouses = Warehouse::query()
                    ->with(['locations' => fn ($query) => $query->orderBy('id')])
                    ->whereKey($warehouses->pluck('id'))
                    ->lockForUpdate()
                    ->get()
                    ->sortBy('id')
                    ->values();
                $canonical = $integrity->canonicalWarehouse($lockedWarehouses);

                if (! $canonical instanceof Warehouse) {
                    throw new \RuntimeException('No se pudo determinar el almacen canonico.');
                }

                $beforeLocations = $integrity->locationsForWarehouses($lockedWarehouses);
                $beforeStock = $integrity->stockSnapshot($beforeLocations, $client);
                $affectedStock = StockPallet::query()
                    ->with(['client', 'item', 'location.warehouse'])
                    ->whereIn('location_id', $beforeLocations->pluck('id'))
                    ->lockForUpdate()
                    ->get();
                $beforeMovementSnapshots = $affectedStock->mapWithKeys(fn (StockPallet $stock): array => [
                    $stock->id => $movements->snapshot($stock),
                ]);
                $duplicateIds = $lockedWarehouses
                    ->where('id', '!=', $canonical->id)
                    ->pluck('id')
                    ->values();

                $this->mergeLocations($integrity, $lockedWarehouses, $canonical);

                foreach (WarehouseIntegrityService::WAREHOUSE_REFERENCES as $table => $column) {
                    DB::table($table)->whereIn($column, $duplicateIds)->update([$column => $canonical->id]);
                }

                $canonicalLocations = Location::query()
                    ->with('warehouse.client')
                    ->where('warehouse_id', $canonical->id)
                    ->orderBy('id')
                    ->get();
                $seriesStatus = $integrity->seriesStatus($canonicalLocations, $client);

                foreach ($seriesStatus['missing'] as $code) {
                    Location::query()->create([
                        'warehouse_id' => $canonical->id,
                        'code' => $code,
                        'name' => 'Calle '.$code,
                        'aisle' => $code,
                        'active' => true,
                    ]);
                }

                foreach ($lockedWarehouses->where('id', '!=', $canonical->id) as $warehouse) {
                    $remainingLocations = Location::query()->where('warehouse_id', $warehouse->id)->count();
                    $remainingReferences = DB::table('bookings')->where('warehouse_id', $warehouse->id)->count();

                    if ($remainingLocations === 0 && $remainingReferences === 0) {
                        $warehouse->delete();
                    } else {
                        $warehouse->forceFill(['active' => false])->save();
                    }
                }

                $afterWarehouses = $integrity->candidateWarehouses($client, $warehouseCode);
                $afterLocations = $integrity->locationsForWarehouses($afterWarehouses);
                $afterStock = $integrity->stockSnapshot($afterLocations, $client);

                if ($beforeStock !== $afterStock) {
                    throw new \RuntimeException('La verificacion de stock no coincide; la transaccion se ha revertido.');
                }

                if (Location::query()->whereIn('warehouse_id', $duplicateIds)->exists()) {
                    throw new \RuntimeException('Quedan ubicaciones en almacenes duplicados; la transaccion se ha revertido.');
                }

                if (DB::table('bookings')->whereIn('warehouse_id', $duplicateIds)->exists()) {
                    throw new \RuntimeException('Quedan reservas en almacenes duplicados; la transaccion se ha revertido.');
                }

                foreach ($affectedStock as $stock) {
                    $afterStockPallet = StockPallet::query()->with(['client', 'item', 'location.warehouse'])->findOrFail($stock->id);
                    $beforeSnapshot = $beforeMovementSnapshots->get($stock->id);
                    $afterSnapshot = $movements->snapshot($afterStockPallet);

                    if (($beforeSnapshot['location_id'] ?? null) === ($afterSnapshot['location_id'] ?? null)
                        && ($beforeSnapshot['warehouse_id'] ?? null) === ($afterSnapshot['warehouse_id'] ?? null)) {
                        continue;
                    }

                    $movementType = ($beforeSnapshot['location_id'] ?? null) !== ($afterSnapshot['location_id'] ?? null)
                        ? InventoryMovement::LOCATION_CONSOLIDATION
                        : InventoryMovement::WAREHOUSE_CONSOLIDATION;
                    $movements->record(
                        before: $beforeSnapshot,
                        after: $afterSnapshot,
                        movementType: $movementType,
                        idempotencyKey: "warehouse-dedup:{$canonical->id}:stock:{$stock->id}:".($beforeSnapshot['location_id'] ?? 0).':'.($afterSnapshot['location_id'] ?? 0),
                        correlationId: $correlationId,
                        source: $canonical,
                        metadata: ['merged_warehouse_ids' => $duplicateIds->all()],
                    );
                }

                $audit->record(
                    event: 'warehouse_consolidated',
                    module: 'warehouses',
                    description: 'Almacenes y ubicaciones duplicados consolidados sin alterar cantidades.',
                    auditable: $canonical,
                    clientId: $client?->id ?? $canonical->client_id,
                    oldValues: ['warehouse_ids' => $lockedWarehouses->pluck('id')->all()],
                    newValues: ['canonical_warehouse_id' => $canonical->id],
                    metadata: ['affected_stock_pallets' => $affectedStock->count()],
                    correlationId: $correlationId,
                    source: 'command',
                    severity: 'important',
                );

                Log::info('Almacenes duplicados consolidados.', [
                    'canonical_warehouse_id' => $canonical->id,
                    'merged_warehouse_ids' => $duplicateIds->all(),
                    'warehouse_code' => $warehouseCode,
                ]);
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se pudo completar la consolidacion: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Aplicacion completada. Almacenes, ubicaciones y stock verificados sin cambios de negocio.');

        return self::SUCCESS;
    }

    /** @param Collection<int, Warehouse> $warehouses
     * @param  Collection<int, Location>  $locations
     * @param  Collection<int, Collection<int, Location>>  $duplicateLocationGroups
     * @return list<string>
     */
    private function reportPlan(
        WarehouseIntegrityService $integrity,
        Collection $warehouses,
        Collection $locations,
        Collection $duplicateLocationGroups,
        ?Client $client,
    ): array {
        $canonical = $integrity->canonicalWarehouse($warehouses);

        $this->line('Almacenes detectados: '.$warehouses->count());
        $this->table(
            ['Accion', 'ID', 'Cliente', 'Codigo', 'Nombre', 'Activo', 'Ubic.', 'Stock', 'Entradas', 'Salidas', 'Articulos', 'Bookings'],
            $warehouses->map(function (Warehouse $warehouse) use ($integrity, $canonical): array {
                $counts = $integrity->warehouseReferenceCounts($warehouse);

                return [
                    $canonical instanceof Warehouse && $warehouse->id === $canonical->id ? 'CONSERVAR' : 'FUSIONAR',
                    $warehouse->id,
                    $warehouse->client?->code ?? 'GLOBAL',
                    $warehouse->code,
                    $warehouse->name,
                    $warehouse->active ? 'si' : 'no',
                    $counts['locations'],
                    $counts['stock'],
                    $counts['receipts'],
                    $counts['dispatches'],
                    $counts['items'],
                    $counts['bookings'],
                ];
            })->all(),
        );

        if ($locations->isNotEmpty()) {
            $this->line('Ubicaciones por almacen 38:');
            $this->table(
                ['Almacen ID', 'Ubicacion ID', 'Codigo', 'Activa', 'Stock', 'Entradas', 'Salidas', 'Articulos'],
                $locations->map(function (Location $location) use ($integrity): array {
                    $counts = $integrity->locationReferenceCounts($location);

                    return [
                        $location->warehouse_id,
                        $location->id,
                        $location->code,
                        $location->active ? 'si' : 'no',
                        $counts['stock'],
                        $counts['receipts'],
                        $counts['dispatches'],
                        $counts['items'],
                    ];
                })->all(),
            );
        }

        foreach ($duplicateLocationGroups as $group) {
            if (! $canonical instanceof Warehouse) {
                continue;
            }

            $canonicalLocation = $integrity->canonicalLocation($group, $canonical);
            $this->line(sprintf(
                'Ubicacion duplicada %s | conservar #%d en almacen #%d',
                LocationCode::normalize($canonicalLocation->code),
                $canonicalLocation->id,
                $canonical->id,
            ));
        }

        $canonicalLocations = $canonical instanceof Warehouse
            ? $locations->where('warehouse_id', $canonical->id)
            : collect();
        $seriesStatus = $integrity->seriesStatus($canonicalLocations, $client);

        if ($seriesStatus['missing'] !== []) {
            $this->line('Ubicaciones esperadas por crear en canonico: '.implode(', ', $seriesStatus['missing']));
        }

        if ($seriesStatus['extras'] !== []) {
            $this->line('Extras a conservar: '.implode(', ', $seriesStatus['extras']));
        }

        return $seriesStatus['missing'];
    }

    /** @param Collection<int, Warehouse> $warehouses */
    private function mergeLocations(WarehouseIntegrityService $integrity, Collection $warehouses, Warehouse $canonicalWarehouse): void
    {
        $warehouseIds = $warehouses->pluck('id');
        $locations = Location::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
        $groups = $integrity->duplicateLocationGroupsAcrossWarehouses($locations);

        foreach ($groups as $group) {
            $canonical = $integrity->canonicalLocation($group, $canonicalWarehouse);
            $duplicateIds = $group->where('id', '!=', $canonical->id)->pluck('id')->values();

            foreach (LocationIntegrityService::REFERENCES as $table => $column) {
                DB::table($table)->whereIn($column, $duplicateIds)->update([$column => $canonical->id]);
            }

            foreach (LocationIntegrityService::REFERENCES as $table => $column) {
                if (DB::table($table)->whereIn($column, $duplicateIds)->exists()) {
                    throw new \RuntimeException("Quedan referencias en {$table}; la transaccion se ha revertido.");
                }
            }

            Location::query()->whereKey($duplicateIds)->delete();
            DB::table('locations')->where('id', $canonical->id)->update([
                'warehouse_id' => $canonicalWarehouse->id,
                'code' => LocationCode::normalize($canonical->code),
                'updated_at' => now(),
            ]);
        }

        Location::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('warehouse_id', '!=', $canonicalWarehouse->id)
            ->update([
                'warehouse_id' => $canonicalWarehouse->id,
                'updated_at' => now(),
            ]);
    }
}
