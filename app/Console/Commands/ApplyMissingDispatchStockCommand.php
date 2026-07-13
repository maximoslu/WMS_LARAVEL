<?php

namespace App\Console\Commands;

use App\Models\GoodsDispatch;
use App\Services\GoodsDispatches\GoodsDispatchWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ApplyMissingDispatchStockCommand extends Command
{
    protected $signature = 'wms:dispatches:apply-missing-stock
        {--dry-run : Lista las salidas afectadas sin modificar datos}
        {--dispatch= : Aplica la reparacion solo a esta salida}
        {--repair-warehouse : Repara solo pallets almacen cuando las unidades ya se descontaron}';

    protected $description = 'Detecta y repara de forma explicita salidas enviadas sin descuento de stock';

    public function handle(GoodsDispatchWorkflowService $workflowService): int
    {
        $dispatchId = $this->option('dispatch');
        $dryRun = (bool) $this->option('dry-run');
        $repairWarehouse = (bool) $this->option('repair-warehouse');

        if ($dispatchId === null && ! $dryRun) {
            $this->error('No se ha modificado nada. Usa --dry-run o indica --dispatch=ID.');

            return self::FAILURE;
        }

        if ($dispatchId !== null) {
            return $this->handleDispatch(
                $workflowService,
                (int) $dispatchId,
                $dryRun,
                $repairWarehouse,
            );
        }

        $dispatches = GoodsDispatch::query()
            ->with(['client:id,name,code', 'lines'])
            ->whereIn('status', [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED])
            ->whereNull('stock_applied_at')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();

        if ($dispatches->isEmpty()) {
            $this->info('Dry-run: no hay salidas enviadas o completadas sin stock aplicado.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Salida', 'Cliente', 'Estado', 'Fecha', 'Detalle carga real', 'Pallets reales', 'Unidades reales'],
            $dispatches->map(fn (GoodsDispatch $dispatch): array => $this->summary($dispatch))->all(),
        );
        $this->warn(sprintf('Dry-run: %d salida(s) requieren revision. No se ha modificado ningun dato.', $dispatches->count()));

        return self::SUCCESS;
    }

    private function handleDispatch(
        GoodsDispatchWorkflowService $workflowService,
        int $dispatchId,
        bool $dryRun,
        bool $repairWarehouse,
    ): int {
        $dispatch = GoodsDispatch::query()
            ->with(['client:id,name,code', 'lines'])
            ->find($dispatchId);

        if (! $dispatch instanceof GoodsDispatch) {
            $this->error("No existe la salida {$dispatchId}.");

            return self::FAILURE;
        }

        $this->table(
            ['ID', 'Salida', 'Cliente', 'Estado', 'Fecha', 'Detalle carga real', 'Pallets reales', 'Unidades reales'],
            [$this->summary($dispatch)],
        );

        if (! in_array($dispatch->status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true)) {
            $this->error('No se ha modificado nada: la salida no esta enviada ni completada.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line($this->plannedAction($dispatch, $repairWarehouse));
            $this->info('Dry-run: no se ha modificado ningun dato.');

            return self::SUCCESS;
        }

        try {
            if (! $dispatch->hasStockApplied()) {
                $applied = $workflowService->applyMissingStock($dispatch);
                $this->info($applied
                    ? 'Stock de unidades y pallets almacen aplicado; la salida ha quedado marcada.'
                    : 'La salida ya tenia el stock aplicado.');

                return self::SUCCESS;
            }

            if (! $repairWarehouse) {
                $this->warn('Las unidades ya estaban aplicadas. No se ha modificado nada. Usa --repair-warehouse solo si has verificado el KPI historico de esta salida.');

                return self::SUCCESS;
            }

            $applied = $workflowService->repairWarehouseStock($dispatch);
            $this->info($applied
                ? 'Pallets almacen reparados sin volver a descontar unidades; la salida ha quedado marcada.'
                : 'La reparacion de pallets almacen ya estaba aplicada.');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            $this->error('La transaccion se ha revertido completa; no se ha aplicado una reparacion parcial.');

            return self::FAILURE;
        }
    }

    /** @return array<int, int|string> */
    private function summary(GoodsDispatch $dispatch): array
    {
        return [
            $dispatch->id,
            $dispatch->dispatchNumber(),
            $dispatch->client?->code ?: $dispatch->client?->name ?: 'Sin cliente',
            $dispatch->status,
            $dispatch->sent_at?->format('Y-m-d H:i') ?? '-',
            $dispatch->lines
                ->map(fn ($line): string => sprintf(
                    '%s: %d pallet(s), %d ud parciales',
                    $line->sku ?: 'Sin SKU',
                    $line->loadedPallets(),
                    $line->loadedPartialUnits(),
                ))
                ->implode(' | '),
            $dispatch->loadedPalletsCount(),
            $dispatch->loadedUnitsCount(),
        ];
    }

    private function plannedAction(GoodsDispatch $dispatch, bool $repairWarehouse): string
    {
        if (! $dispatch->hasStockApplied()) {
            return 'Accion prevista: aplicar carga real a unidades y pallets almacen, y marcar stock_applied_at.';
        }

        if ($repairWarehouse && ! $dispatch->hasWarehouseStockApplied()) {
            return 'Accion prevista: descontar solo pallets almacen, sin tocar quantity_units, y registrar la reparacion.';
        }

        return 'Accion prevista: ninguna; el descuento solicitado ya consta como aplicado.';
    }
}
