<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Traceability\TraceabilityBackfillService;
use Illuminate\Console\Command;

class TraceabilityBackfillCommand extends Command
{
    protected $signature = 'wms:traceability:backfill
        {--client= : Codigo, nombre o ID del cliente}
        {--dry-run : Inspecciona sin modificar datos}
        {--apply : Crea unicamente registros de trazabilidad}';

    protected $description = 'Reconstruye trazabilidad historica verificable sin modificar operaciones ni stock';

    public function handle(TraceabilityBackfillService $backfill): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Usa --dry-run o --apply, no ambos.');

            return self::FAILURE;
        }

        $client = $this->resolveClient($this->option('client'));

        if ($this->option('client') !== null && ! $client instanceof Client) {
            $this->error('No se ha encontrado el cliente indicado.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $summary = $backfill->run($client?->id, $apply);

        $this->table(['Concepto', 'Cantidad'], collect($summary)->map(fn (int $value, string $key): array => [$key, $value])->values()->all());
        $this->{$apply ? 'info' : 'warn'}($apply
            ? 'Apply completado. Solo se han creado registros de trazabilidad.'
            : 'Dry-run completado. No se ha modificado ningun dato.');

        return self::SUCCESS;
    }

    private function resolveClient(mixed $filter): ?Client
    {
        $filter = trim((string) $filter);

        if ($filter === '') {
            return null;
        }

        return Client::query()->where(function ($query) use ($filter): void {
            $query->where('code', $filter)->orWhere('name', $filter);

            if (ctype_digit($filter)) {
                $query->orWhereKey((int) $filter);
            }
        })->first();
    }
}
