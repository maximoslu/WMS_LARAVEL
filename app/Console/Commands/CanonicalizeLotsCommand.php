<?php

namespace App\Console\Commands;

use App\Services\Stock\LotCanonicalizationService;
use Illuminate\Console\Command;

class CanonicalizeLotsCommand extends Command
{
    protected $signature = 'wms:lots:canonicalize
        {--client= : Codigo, nombre o ID del cliente}
        {--table= : Limita a una tabla concreta}
        {--dry-run : Muestra el impacto sin modificar datos}
        {--apply : Aplica cambios seguros}';

    protected $description = 'Audita y normaliza aliases de lotes vacios al valor canonico NO LOTE.';

    public function handle(LotCanonicalizationService $service): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Usa --dry-run o --apply, no ambos.');

            return self::FAILURE;
        }

        $clientFilter = $this->option('client');
        $client = $service->resolveClient(is_string($clientFilter) ? $clientFilter : null);

        if ($clientFilter !== null && trim((string) $clientFilter) !== '' && $client === null) {
            $this->error('No se ha encontrado el cliente indicado.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $result = $service->run(
            clientFilter: is_string($clientFilter) ? $clientFilter : null,
            tableFilter: is_string($this->option('table')) ? $this->option('table') : null,
            apply: $apply,
        );

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' wms:lots:canonicalize');
        $this->line('Valor canonico: '.$result['canonical_lot']);
        $this->line('Cliente: '.($client ? $client->code.' / '.$client->name : 'todos'));
        $this->newLine();

        foreach ($result['tables'] as $table) {
            $this->line(sprintf(
                '%s | escaneados: %d | a normalizar: %d',
                $table['table'],
                $table['records_scanned'],
                $table['records_to_normalize'],
            ));

            foreach ($table['variants'] as $variant => $count) {
                $this->line('  - '.$variant.': '.$count);
            }

            if ($table['table'] === 'stock_pallets') {
                $this->line('  - cambios simples: '.$table['simple_text_updates']);
                $this->line('  - grupos con colision: '.$table['collision_groups']);
            }
        }

        $this->newLine();
        $this->line('Registros cambiados: '.$result['records_changed']);
        $this->line('Registros eliminados por consolidacion: '.$result['records_deleted']);
        $this->line('Grupos stock consolidados: '.$result['stock_consolidated_groups']);
        $this->line('Conflictos no consolidados: '.count($result['stock_conflicts']));
        $this->line('Unidades antes/despues: '.$result['units_before'].' / '.$result['units_after']);
        $this->line('Pallets completos antes/despues: '.$result['full_pallets_before'].' / '.$result['full_pallets_after']);
        $this->line('Picos antes/despues: '.$result['peaks_before'].' / '.$result['peaks_after']);
        $this->line('Pallets almacen antes/despues: '.$result['warehouse_pallets_before'].' / '.$result['warehouse_pallets_after']);

        if ($result['stock_collisions'] !== []) {
            $this->newLine();
            $this->line('Colisiones de stock detectadas:');

            foreach ($result['stock_collisions'] as $collision) {
                $this->line(sprintf(
                    '  - IDs [%s] | lotes [%s] | unidades: %d | pallets: %d | picos: %d',
                    implode(', ', $collision['ids']),
                    implode(', ', array_map(fn (mixed $lot): string => $lot === null ? '[NULL]' : (string) $lot, $collision['lots'])),
                    $collision['units_before'],
                    $collision['full_pallets_before'],
                    $collision['peaks_before'],
                ));
            }
        }

        if ($result['stock_conflicts'] !== []) {
            $this->newLine();
            $this->warn('Conflictos pendientes de revision manual: '.count($result['stock_conflicts']));
        }

        if (! $apply) {
            $this->warn('Dry-run: no se ha modificado ningun dato. Usa --apply solo tras revisar este informe.');
        }

        return self::SUCCESS;
    }
}
