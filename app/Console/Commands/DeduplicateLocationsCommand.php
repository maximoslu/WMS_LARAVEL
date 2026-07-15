<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Location;
use App\Support\Locations\LocationCode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeduplicateLocationsCommand extends Command
{
    protected $signature = 'wms:locations:deduplicate
        {--client= : Codigo, nombre o ID del cliente}
        {--warehouse= : Codigo, nombre o ID del almacen}
        {--dry-run : Muestra el plan sin modificar datos}
        {--apply : Reasigna referencias y elimina solo duplicados sin referencias}';

    protected $description = 'Detecta y consolida ubicaciones duplicadas por almacen y codigo normalizado';

    /** @var array<string, string> */
    private const REFERENCES = [
        'stock_pallets' => 'location_id',
        'goods_receipt_lines' => 'location_id',
        'items' => 'default_location_id',
    ];

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Usa --dry-run o --apply, no ambos a la vez.');

            return self::FAILURE;
        }

        $client = $this->resolveClient();

        if ($this->option('client') !== null && ! $client instanceof Client) {
            $this->error('No se ha encontrado el cliente indicado. No se ha modificado nada.');

            return self::FAILURE;
        }

        $groups = $this->duplicateGroups($client);

        if ($groups->isEmpty()) {
            $this->info('No hay ubicaciones duplicadas para los filtros indicados. No se ha modificado ningun dato.');

            return self::SUCCESS;
        }

        foreach ($groups as $group) {
            $canonical = $this->canonicalLocation($group);
            $duplicates = $group->where('id', '!=', $canonical->id)->values();

            $this->newLine();
            $this->line(sprintf(
                'Almacen %s | codigo normalizado %s | canonica #%d (%s)',
                $canonical->warehouse?->name ?: $canonical->warehouse?->code ?: $canonical->warehouse_id,
                LocationCode::normalize($canonical->code),
                $canonical->id,
                $canonical->code,
            ));
            $this->table(
                ['Accion', 'ID', 'Codigo', 'Activa', 'Stock', 'Entradas', 'Articulos', 'Salidas/asignaciones'],
                $group->map(fn (Location $location): array => [
                    $location->id === $canonical->id ? 'CONSERVAR' : 'FUSIONAR',
                    $location->id,
                    $location->code,
                    $location->active ? 'si' : 'no',
                    $this->referenceCount('stock_pallets', 'location_id', $location->id),
                    $this->referenceCount('goods_receipt_lines', 'location_id', $location->id),
                    $this->referenceCount('items', 'default_location_id', $location->id),
                    0,
                ])->all(),
            );

            if ($this->option('apply')) {
                $this->applyGroup($canonical, $duplicates);
            }
        }

        if (! $this->option('apply')) {
            $this->warn(sprintf('Dry-run: %d grupo(s) duplicado(s). No se ha modificado ningun dato.', $groups->count()));
        } else {
            $this->info(sprintf('Aplicacion completada: %d grupo(s) consolidados.', $groups->count()));
        }

        return self::SUCCESS;
    }

    private function resolveClient(): ?Client
    {
        $filter = trim((string) ($this->option('client') ?? ''));

        if ($filter === '') {
            return null;
        }

        return Client::query()
            ->where(function (Builder $query) use ($filter): void {
                $query->where('code', $filter)->orWhere('name', $filter);

                if (ctype_digit($filter)) {
                    $query->orWhereKey((int) $filter);
                }
            })
            ->first();
    }

    /** @return Collection<int, Collection<int, Location>> */
    private function duplicateGroups(?Client $client): Collection
    {
        $warehouseFilter = trim((string) ($this->option('warehouse') ?? ''));

        return Location::query()
            ->with('warehouse.client')
            ->when($warehouseFilter !== '', function (Builder $query) use ($warehouseFilter): void {
                $query->whereHas('warehouse', function (Builder $warehouseQuery) use ($warehouseFilter): void {
                    $warehouseQuery->where(function (Builder $match) use ($warehouseFilter): void {
                        $match->where('code', $warehouseFilter)->orWhere('name', $warehouseFilter);

                        if (ctype_digit($warehouseFilter)) {
                            $match->orWhereKey((int) $warehouseFilter);
                        }
                    });
                });
            })
            ->when($client instanceof Client, function (Builder $query) use ($client): void {
                $query->where(function (Builder $clientQuery) use ($client): void {
                    $clientQuery
                        ->whereHas('warehouse', fn (Builder $warehouseQuery) => $warehouseQuery->where('client_id', $client->id))
                        ->orWhereHas('warehouse.locations.stockPallets', fn (Builder $stockQuery) => $stockQuery->where('client_id', $client->id))
                        ->orWhereHas('warehouse.locations.defaultItems', fn (Builder $itemQuery) => $itemQuery->where('client_id', $client->id))
                        ->orWhereHas('warehouse.locations.goodsReceiptLines.goodsReceipt', fn (Builder $receiptQuery) => $receiptQuery->where('client_id', $client->id));
                });
            })
            ->orderBy('warehouse_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Location $location): string => $location->warehouse_id.'|'.LocationCode::normalize($location->code))
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->values();
    }

    /** @param Collection<int, Location> $group */
    private function canonicalLocation(Collection $group): Location
    {
        $normalized = LocationCode::normalize($group->first()->code);

        return $group
            ->sortBy(fn (Location $location): array => [
                $location->active ? 0 : 1,
                LocationCode::normalize($location->code) === $location->code && $location->code === $normalized ? 0 : 1,
                $location->id,
            ])
            ->first();
    }

    /** @param Collection<int, Location> $duplicates */
    private function applyGroup(Location $canonical, Collection $duplicates): void
    {
        DB::transaction(function () use ($canonical, $duplicates): void {
            $ids = $duplicates->pluck('id')->all();
            $lockedDuplicates = Location::query()->whereKey($ids)->lockForUpdate()->get();
            $lockedCanonical = Location::query()->whereKey($canonical->id)->lockForUpdate()->firstOrFail();

            foreach (self::REFERENCES as $table => $column) {
                DB::table($table)->whereIn($column, $ids)->update([$column => $lockedCanonical->id]);
            }

            foreach (self::REFERENCES as $table => $column) {
                if (DB::table($table)->whereIn($column, $ids)->exists()) {
                    throw new \RuntimeException("Quedan referencias en {$table}; la transaccion se ha revertido.");
                }
            }

            Location::query()->whereKey($lockedDuplicates->pluck('id'))->delete();
            DB::table('locations')->where('id', $lockedCanonical->id)->update([
                'code' => LocationCode::normalize($lockedCanonical->code),
                'updated_at' => now(),
            ]);

            Log::info('Ubicaciones duplicadas consolidadas.', [
                'canonical_location_id' => $lockedCanonical->id,
                'merged_location_ids' => $ids,
                'normalized_code' => LocationCode::normalize($lockedCanonical->code),
            ]);
        });
    }

    private function referenceCount(string $table, string $column, int $locationId): int
    {
        return DB::table($table)->where($column, $locationId)->count();
    }
}
