<?php

namespace App\Console\Commands;

use App\Models\BackupExport;
use App\Models\Client;
use App\Services\Backups\BackupService;
use Illuminate\Console\Command;

class CreateBackupCommand extends Command
{
    protected $signature = 'wms:backups:create
        {--type= : Tipo: full-system, database, movements, operations, stock, stock-client}
        {--client= : Codigo, nombre o ID del cliente}
        {--dry-run : Valida parametros y muestra el plan sin generar archivo}';

    protected $description = 'Genera una copia de seguridad manual del WMS.';

    public function handle(BackupService $backups): int
    {
        $type = (string) $this->option('type');

        if (! in_array($type, BackupExport::manualTypes(), true)) {
            $this->error('Tipo invalido. Usa: '.implode(', ', BackupExport::manualTypes()));

            return self::FAILURE;
        }

        $client = $this->resolveClient((string) $this->option('client'));

        if ($type === BackupExport::TYPE_STOCK_CLIENT && ! $client instanceof Client) {
            $this->error('El tipo stock-client requiere --client=CODIGO.');

            return self::FAILURE;
        }

        if ($this->option('client') && ! $client instanceof Client) {
            $this->error('No se ha encontrado el cliente indicado.');

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? 'DRY-RUN' : 'APPLY').' wms:backups:create');
        $this->line('Tipo: '.$type);
        $this->line('Cliente: '.($client?->code ?? 'No aplica'));
        $this->line('Destino: '.config('wms.backups.disk', 'local').':'.config('wms.backups.path', 'backups'));

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: no se ha generado ningun archivo.');

            return self::SUCCESS;
        }

        $backup = $backups->createManual($type, $client);

        if ($backup->isCompleted()) {
            $this->info('Backup generado: '.$backup->filename);
            $this->line('Ruta privada: '.$backup->path);

            return self::SUCCESS;
        }

        $this->error('Backup fallido: '.$backup->error_message);

        return self::FAILURE;
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
