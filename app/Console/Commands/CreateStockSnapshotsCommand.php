<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Backups\StockSnapshotService;
use Illuminate\Console\Command;

class CreateStockSnapshotsCommand extends Command
{
    protected $signature = 'wms:backups:stock-snapshots
        {--client= : Codigo, nombre o ID del cliente}
        {--date= : Fecha del snapshot YYYY-MM-DD}
        {--force : Regenera el snapshot si ya existe}
        {--dry-run : Muestra el plan sin generar archivos}';

    protected $description = 'Genera snapshots diarios de stock por cliente.';

    public function handle(StockSnapshotService $snapshots): int
    {
        $client = $this->resolveClient((string) $this->option('client'));

        if ($this->option('client') && ! $client instanceof Client) {
            $this->error('No se ha encontrado el cliente indicado.');

            return self::FAILURE;
        }

        $date = $this->option('date') ?: now()->toDateString();
        $result = $snapshots->createDailySnapshots(
            onlyClient: $client,
            date: (string) $date,
            force: (bool) $this->option('force'),
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->info(($this->option('dry-run') ? 'DRY-RUN' : 'APPLY').' wms:backups:stock-snapshots');
        $this->line('Clientes planificados: '.$result['planned']);
        $this->line('Snapshots creados: '.$result['created']);
        $this->line('Snapshots omitidos: '.$result['skipped']);

        foreach (array_slice($result['items'], 0, 10) as $item) {
            $this->line(' - '.$item['client_code'].' / '.$item['action'].' / '.$item['path']);
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: no se ha generado ningun archivo.');
        }

        return self::SUCCESS;
    }

    private function resolveClient(string $filter): ?Client
    {
        $filter = trim($filter);

        if ($filter === '') {
            return null;
        }

        return Client::query()
            ->where('code', $filter)
            ->orWhere('name', $filter)
            ->when(ctype_digit($filter), fn ($query) => $query->orWhere('id', (int) $filter))
            ->first();
    }
}
