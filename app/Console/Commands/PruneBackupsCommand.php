<?php

namespace App\Console\Commands;

use App\Models\BackupExport;
use App\Services\Backups\StockSnapshotService;
use Illuminate\Console\Command;

class PruneBackupsCommand extends Command
{
    protected $signature = 'wms:backups:prune
        {--days=365 : Dias de retencion}
        {--type=stock_snapshot_daily : Tipo de backup a limpiar}
        {--dry-run : Muestra el impacto sin borrar archivos}
        {--apply : Borra archivos y metadatos antiguos}';

    protected $description = 'Limpia backups antiguos segun retencion configurada.';

    public function handle(StockSnapshotService $snapshots): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Usa --dry-run o --apply, no ambos.');

            return self::FAILURE;
        }

        $type = (string) $this->option('type');
        if (! array_key_exists($type, BackupExport::typeLabels())) {
            $this->error('Tipo de backup no valido.');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('apply');
        $result = $snapshots->prune((int) $this->option('days'), $type, $dryRun);

        $this->info(($dryRun ? 'DRY-RUN' : 'APPLY').' wms:backups:prune');
        $this->line('Tipo: '.$type);
        $this->line('Registros antiguos encontrados: '.$result['matched']);
        $this->line('Registros borrados: '.$result['deleted']);

        foreach (array_slice($result['items'], 0, 10) as $item) {
            $this->line(' - #'.$item['id'].' / '.$item['created_at'].' / '.$item['path']);
        }

        if ($dryRun) {
            $this->warn('Dry-run: no se ha borrado ningun archivo ni registro.');
        }

        return self::SUCCESS;
    }
}
