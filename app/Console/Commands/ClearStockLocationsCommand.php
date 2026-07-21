<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\StockPallet;
use App\Services\Locations\LocationIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ClearStockLocationsCommand extends Command
{
    protected $signature = 'wms:stock:clear-locations
        {--client= : Codigo, nombre o ID del cliente}
        {--warehouse= : Codigo, nombre o ID del almacen vinculado}
        {--dry-run : Muestra el impacto sin modificar datos}
        {--apply : Aplica el borrado de ubicaciones de stock}';

    protected $description = 'Limpia location_id y location_text de partidas de stock sin cambiar cantidades ni historico.';

    public function handle(LocationIntegrityService $locations): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Usa --dry-run o --apply, no ambos.');

            return self::FAILURE;
        }

        $client = $locations->resolveClient((string) $this->option('client'));
        if ($this->option('client') && ! $client instanceof Client) {
            $this->error('No se ha encontrado el cliente indicado.');

            return self::FAILURE;
        }

        $warehouseFilter = trim((string) $this->option('warehouse'));
        $query = $this->affectedQuery($client, $warehouseFilter);
        $affected = (clone $query)->count();
        $withLocationId = (clone $query)->whereNotNull('location_id')->count();
        $withLocationText = (clone $query)->whereNotNull('location_text')->where('location_text', '<>', '')->count();

        $this->info(($this->option('apply') ? 'APPLY' : 'DRY-RUN').' wms:stock:clear-locations');
        $this->line('Partidas afectadas: '.$affected);
        $this->line('location_id a vaciar: '.$withLocationId);
        $this->line('location_text a vaciar: '.$withLocationText);

        (clone $query)
            ->leftJoin('clients', 'clients.id', '=', 'stock_pallets.client_id')
            ->leftJoin('locations', 'locations.id', '=', 'stock_pallets.location_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'locations.warehouse_id')
            ->selectRaw("COALESCE(clients.name, 'Sin cliente') as client_name")
            ->selectRaw("COALESCE(warehouses.name, warehouses.code, 'Sin almacen vinculado') as warehouse_name")
            ->selectRaw('COUNT(*) as total')
            ->groupByRaw("COALESCE(clients.name, 'Sin cliente')")
            ->groupByRaw("COALESCE(warehouses.name, warehouses.code, 'Sin almacen vinculado')")
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->each(function (object $group): void {
                $this->line(" - {$group->client_name} / {$group->warehouse_name}: {$group->total}");
            });

        if (! $this->option('apply')) {
            $this->warn('No se han modificado datos. Para aplicar, ejecuta con --apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($query): void {
            $query->update([
                'location_id' => null,
                'location_text' => null,
                'updated_at' => now(),
            ]);
        });

        $this->info('Ubicaciones de stock limpiadas correctamente.');

        return self::SUCCESS;
    }

    private function affectedQuery(?Client $client, string $warehouseFilter): Builder
    {
        return StockPallet::query()
            ->when($client instanceof Client, fn (Builder $query) => $query->where('stock_pallets.client_id', $client->id))
            ->when($warehouseFilter !== '', function (Builder $query) use ($warehouseFilter): void {
                $query->whereHas('location.warehouse', function (Builder $warehouseQuery) use ($warehouseFilter): void {
                    $warehouseQuery->where(function (Builder $match) use ($warehouseFilter): void {
                        $match
                            ->where('code', $warehouseFilter)
                            ->orWhere('name', $warehouseFilter);

                        if (ctype_digit($warehouseFilter)) {
                            $match->orWhere('id', (int) $warehouseFilter);
                        }
                    });
                });
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('stock_pallets.location_id')
                    ->orWhere(function (Builder $textQuery): void {
                        $textQuery->whereNotNull('stock_pallets.location_text')->where('stock_pallets.location_text', '<>', '');
                    });
            });
    }
}
