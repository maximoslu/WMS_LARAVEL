<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Item;
use App\Services\Traceability\StockAlertEvaluationService;
use Illuminate\Console\Command;

class EvaluateStockAlertsCommand extends Command
{
    protected $signature = 'wms:stock-alerts:evaluate
        {--client= : Codigo, nombre o ID del cliente}
        {--item= : ID o SKU del articulo}
        {--dry-run : Evalua sin modificar estados ni enviar emails}
        {--apply : Registra eventos y encola avisos cuando corresponda}';

    protected $description = 'Evalua reglas de stock con control de ruido y prevision explicable';

    public function handle(StockAlertEvaluationService $alerts): int
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

        $item = $this->resolveItem($this->option('item'), $client?->id);

        if ($this->option('item') !== null && ! $item instanceof Item) {
            $this->error('No se ha encontrado el articulo indicado para el cliente.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $summary = $alerts->evaluate($client?->id, $item?->id, $apply);
        $this->table(
            ['Regla', 'Cliente', 'SKU', 'Estado', 'Accion', 'Motivo'],
            collect($summary['rows'])->map(fn (array $row): array => [
                $row['rule_id'], $row['client'], $row['sku'], $row['severity'], $row['action'], $row['reason'],
            ])->all(),
        );
        $this->line("Evaluadas: {$summary['evaluated']} | disparadas: {$summary['triggered']} | resueltas: {$summary['resolved']} | sin cambio: {$summary['unchanged']}");
        $this->{$apply ? 'info' : 'warn'}($apply
            ? 'Evaluacion aplicada; los emails necesarios se han encolado tras commit.'
            : 'Dry-run completado. No se han modificado estados ni enviado emails.');

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

    private function resolveItem(mixed $filter, ?int $clientId): ?Item
    {
        $filter = trim((string) $filter);

        if ($filter === '') {
            return null;
        }

        return Item::query()
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->where(function ($query) use ($filter): void {
                $query->where('sku', $filter);
                if (ctype_digit($filter)) {
                    $query->orWhereKey((int) $filter);
                }
            })
            ->first();
    }
}
