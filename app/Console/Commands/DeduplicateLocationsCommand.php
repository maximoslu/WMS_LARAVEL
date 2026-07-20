<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Location;
use App\Services\Locations\LocationIntegrityService;
use App\Support\Locations\LocationCode;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeduplicateLocationsCommand extends Command
{
    protected $signature = 'wms:locations:deduplicate
        {--client= : Codigo, nombre o ID del cliente}
        {--warehouse= : Codigo, nombre o ID del almacen}
        {--dry-run : Muestra el plan sin modificar datos}
        {--apply : Consolida duplicados y crea las ubicaciones esperadas que falten}';

    protected $description = 'Consolida ubicaciones equivalentes sin perder referencias ni stock';

    public function handle(LocationIntegrityService $integrity): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Usa --dry-run o --apply, no ambos a la vez.');

            return self::FAILURE;
        }

        $client = $integrity->resolveClient($this->option('client'));

        if ($this->option('client') !== null && ! $client instanceof Client) {
            $this->error('No se ha encontrado el cliente indicado. No se ha modificado nada.');

            return self::FAILURE;
        }

        $locations = $integrity->locations($client, $this->option('warehouse'));

        if ($locations->isEmpty()) {
            $this->warn('No hay ubicaciones para los filtros indicados. No se ha modificado ningun dato.');

            return self::SUCCESS;
        }

        $groups = $integrity->duplicateGroups($locations);
        $missingByWarehouse = $this->reportPlan($integrity, $locations, $groups, $client);
        $missingCount = $missingByWarehouse->sum(fn (array $codes): int => count($codes));

        if (! $this->option('apply')) {
            $this->warn(sprintf(
                'Dry-run: %d grupo(s) duplicado(s), %d ubicacion(es) por crear. No se ha modificado ningun dato.',
                $groups->count(),
                $missingCount,
            ));

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($integrity, $locations, $groups, $missingByWarehouse, $client): void {
                $before = $integrity->stockSnapshot($locations, $client);

                foreach ($groups as $group) {
                    $this->applyGroup($integrity, $group);
                }

                foreach ($missingByWarehouse as $warehouseId => $codes) {
                    foreach ($codes as $code) {
                        Location::query()->create([
                            'warehouse_id' => $warehouseId,
                            'code' => $code,
                            'name' => 'Calle '.$code,
                            'aisle' => $code,
                            'active' => true,
                        ]);
                    }
                }

                $currentLocations = $integrity->locations($client, $this->option('warehouse'));
                $after = $integrity->stockSnapshot($currentLocations, $client);

                if ($before !== $after) {
                    throw new \RuntimeException('La verificacion de stock no coincide; la transaccion se ha revertido.');
                }
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se pudo completar la consolidacion: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Aplicacion completada: %d grupo(s) consolidados y %d ubicacion(es) creadas. Stock verificado sin cambios.',
            $groups->count(),
            $missingCount,
        ));

        return self::SUCCESS;
    }

    /** @param Collection<int, Location> $locations
     * @param  Collection<int, Collection<int, Location>>  $groups
     * @return Collection<int, list<string>>
     */
    private function reportPlan(
        LocationIntegrityService $integrity,
        Collection $locations,
        Collection $groups,
        ?Client $client,
    ): Collection {
        foreach ($groups as $group) {
            $canonical = $integrity->canonicalLocation($group);

            $this->newLine();
            $this->line(sprintf(
                'Almacen %s | codigo normalizado %s | canonica #%d (%s)',
                $canonical->warehouse?->name ?: $canonical->warehouse?->code ?: $canonical->warehouse_id,
                LocationCode::normalize($canonical->code),
                $canonical->id,
                $canonical->code,
            ));
            $this->table(
                ['Accion', 'ID', 'Codigo', 'Activa', 'Stock', 'Entradas', 'Articulos', 'Movimientos'],
                $group->map(function (Location $location) use ($canonical, $integrity): array {
                    $references = $integrity->referenceCounts($location->id);

                    return [
                        $location->id === $canonical->id ? 'CONSERVAR' : 'FUSIONAR',
                        $location->id,
                        $location->code,
                        $location->active ? 'si' : 'no',
                        $references['stock'],
                        $references['receipts'],
                        $references['items'],
                        $references['movements'],
                    ];
                })->all(),
            );
        }

        return $locations
            ->groupBy('warehouse_id')
            ->map(function (Collection $warehouseLocations) use ($integrity, $client): array {
                $status = $integrity->seriesStatus($warehouseLocations, $client);
                $warehouse = $warehouseLocations->first()->warehouse;
                $label = $warehouse?->name ?: $warehouse?->code ?: $warehouseLocations->first()->warehouse_id;

                if ($status['missing'] !== []) {
                    $this->line('Almacen '.$label.' | crear faltantes: '.implode(', ', $status['missing']));
                }

                if ($status['extras'] !== []) {
                    $this->line('Almacen '.$label.' | extras a conservar: '.implode(', ', $status['extras']));
                }

                return $status['missing'];
            });
    }

    /** @param Collection<int, Location> $group */
    private function applyGroup(LocationIntegrityService $integrity, Collection $group): void
    {
        $canonical = $integrity->canonicalLocation($group);
        $duplicateIds = $group->where('id', '!=', $canonical->id)->pluck('id')->all();
        $lockedCanonical = Location::query()->whereKey($canonical->id)->lockForUpdate()->firstOrFail();
        Location::query()->whereKey($duplicateIds)->lockForUpdate()->get();

        foreach (LocationIntegrityService::REFERENCES as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->whereIn($column, $duplicateIds)->update([$column => $lockedCanonical->id]);
            }
        }

        foreach (LocationIntegrityService::REFERENCES as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->whereIn($column, $duplicateIds)->exists()) {
                    throw new \RuntimeException("Quedan referencias en {$table}.{$column}; la transaccion se ha revertido.");
                }
            }
        }

        Location::query()->whereKey($duplicateIds)->delete();
        DB::table('locations')->where('id', $lockedCanonical->id)->update([
            'code' => LocationCode::normalize($lockedCanonical->code),
            'updated_at' => now(),
        ]);

        Log::info('Ubicaciones duplicadas consolidadas.', [
            'canonical_location_id' => $lockedCanonical->id,
            'merged_location_ids' => $duplicateIds,
            'normalized_code' => LocationCode::normalize($lockedCanonical->code),
        ]);
    }
}
