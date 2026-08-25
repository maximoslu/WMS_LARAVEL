<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\GoodsDispatch;
use App\Models\GoodsReceipt;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyOperationRecalculationService
{
    public function __construct(
        private readonly DailyOperationTotalsService $totalsService,
        private readonly DailyOperationHistoricalRebuildService $historicalRebuildService,
        private readonly AuditLogService $audit,
    ) {}

    public function rebuildForDateAndClient(string $operationDate, int $clientId, int $userId): DailyOperationDay
    {
        $date = Carbon::parse($operationDate)->toDateString();

        return DB::transaction(function () use ($date, $clientId, $userId): DailyOperationDay {
            $day = DailyOperationDay::query()
                ->whereDate('operation_date', $date)
                ->where('client_id', $clientId)
                ->first();

            if ($day === null) {
                $day = DailyOperationDay::query()->create([
                    'operation_date' => $date,
                    'client_id' => $clientId,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
            }

            $previousValues = [
                'opening_pallets' => $day->opening_pallets,
                'stored_pallets_today' => $day->stored_pallets_today,
                'moved_pallets_today' => $day->moved_pallets_today,
                'expected_pallets_tomorrow' => $day->expected_pallets_tomorrow,
            ];

            $manualSortOrder = (int) $day->lines()->where(function ($query): void {
                $query
                    ->where('is_auto_generated', false)
                    ->orWhere('source_type', DailyOperationLine::SOURCE_MANUAL_LINE);
            })->max('sort_order');

            $day->lines()
                ->where('is_auto_generated', true)
                ->where('source_type', '!=', DailyOperationLine::SOURCE_MANUAL_LINE)
                ->delete();

            $sortOrder = $manualSortOrder;
            $inboundPallets = 0;
            $outboundPallets = 0;

            $receipts = GoodsReceipt::query()
                ->with(['supplier', 'lines'])
                ->where('client_id', $clientId)
                ->where('status', GoodsReceipt::STATUS_CONFIRMED)
                ->whereDate('received_at', $date)
                ->orderBy('id')
                ->get();

            foreach ($receipts as $receipt) {
                $receiptPallets = $this->totalsService->receiptLogisticUnits($receipt);
                $inboundPallets += $receiptPallets;

                if ($receiptPallets > 0) {
                    $this->createAutoLine(
                        $day,
                        ++$sortOrder,
                        DailyOperationLine::SECTION_DESCARGA,
                        (string) ($receipt->supplier?->name ?? 'Entrada confirmada'),
                        $receiptPallets,
                        'Entrada '.$receipt->receipt_number.' confirmada.',
                        DailyOperationLine::SOURCE_GOODS_RECEIPT,
                        (int) $receipt->id,
                        $userId,
                    );
                }

                $this->createAutoLine(
                    $day,
                    ++$sortOrder,
                    DailyOperationLine::SECTION_GESTION_CAMION,
                    (string) ($receipt->supplier?->name ?? 'Entrada confirmada'),
                    1,
                    'Gestion de camion asociada a la entrada '.$receipt->receipt_number.'.',
                    DailyOperationLine::SOURCE_GOODS_RECEIPT,
                    (int) $receipt->id,
                    $userId,
                );

                if ($receipt->camion_propio) {
                    $this->createAutoLine(
                        $day,
                        ++$sortOrder,
                        DailyOperationLine::SECTION_VIAJE_CAMION,
                        (string) ($receipt->supplier?->name ?? 'Entrada confirmada'),
                        1,
                        'Viaje de camion propio asociado a la entrada '.$receipt->receipt_number.'.',
                        DailyOperationLine::SOURCE_GOODS_RECEIPT,
                        (int) $receipt->id,
                        $userId,
                    );
                }
            }

            $dispatches = GoodsDispatch::query()
                ->with(['client', 'lines'])
                ->where('client_id', $clientId)
                ->whereIn('status', [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED])
                ->where(function ($query) use ($date): void {
                    $query
                        ->where(function ($query) use ($date): void {
                            $query->where('status', GoodsDispatch::STATUS_COMPLETED)
                                ->whereDate('completed_at', $date);
                        })
                        ->orWhere(function ($query) use ($date): void {
                            $query->where('status', GoodsDispatch::STATUS_SENT)
                                ->whereDate('sent_at', $date);
                        });
                })
                ->orderBy('id')
                ->get();

            foreach ($dispatches as $dispatch) {
                $dispatchPallets = $this->totalsService->dispatchLogisticUnits($dispatch);
                $outboundPallets += $dispatchPallets;

                if ($dispatchPallets > 0) {
                    $this->createAutoLine(
                        $day,
                        ++$sortOrder,
                        DailyOperationLine::SECTION_ENVIO,
                        (string) ($dispatch->client?->name ?? 'Salida'),
                        $dispatchPallets,
                        'Envio '.$dispatch->dispatchNumber().' en estado '.$dispatch->status.'.',
                        DailyOperationLine::SOURCE_GOODS_DISPATCH,
                        (int) $dispatch->id,
                        $userId,
                    );
                }

                $this->createAutoLine(
                    $day,
                    ++$sortOrder,
                    DailyOperationLine::SECTION_GESTION_CAMION,
                    (string) ($dispatch->client?->name ?? 'Salida'),
                    1,
                    'Gestion de camion asociada al envio '.$dispatch->dispatchNumber().'.',
                    DailyOperationLine::SOURCE_GOODS_DISPATCH,
                    (int) $dispatch->id,
                    $userId,
                );

                if ($dispatch->camion_propio) {
                    $this->createAutoLine(
                        $day,
                        ++$sortOrder,
                        DailyOperationLine::SECTION_VIAJE_CAMION,
                        (string) ($dispatch->client?->name ?? 'Salida'),
                        1,
                        'Viaje de camion propio asociado al envio '.$dispatch->dispatchNumber().'.',
                        DailyOperationLine::SOURCE_GOODS_DISPATCH,
                        (int) $dispatch->id,
                        $userId,
                    );
                }
            }

            $openingPallets = $this->totalsService->usesLiveStockBaseForDate($date)
                ? $this->totalsService->openingPalletsFromCurrentStockForDate($clientId, $inboundPallets, $outboundPallets)
                : $this->historicalRebuildService->openingForDate($date, $clientId, $day);
            $billableStoragePallets = $openingPallets + $inboundPallets;

            if ($billableStoragePallets > 0) {
                $this->createAutoLine(
                    $day,
                    ++$sortOrder,
                    DailyOperationLine::SECTION_ALMACENAJE,
                    'Pallets facturables del dia',
                    $billableStoragePallets,
                    'Stock base reconstruido mas entradas descargadas para la fecha '.$date.'.',
                    DailyOperationLine::SOURCE_STOCK_SNAPSHOT,
                    null,
                    $userId,
                );
            }

            $result = $this->totalsService->syncDay($day, $openingPallets, $day->notes, $userId);

            $this->audit->record(
                event: 'daily_operation_recalculated',
                module: 'daily_operations',
                description: 'Operación diaria recalculada desde movimientos externos y anclas históricas.',
                auditable: $result,
                user: User::query()->find($userId),
                clientId: $clientId,
                oldValues: $previousValues,
                newValues: [
                    'opening_pallets' => $result->opening_pallets,
                    'stored_pallets_today' => $result->stored_pallets_today,
                    'moved_pallets_today' => $result->moved_pallets_today,
                    'expected_pallets_tomorrow' => $result->expected_pallets_tomorrow,
                ],
                metadata: ['operation_date' => $date, 'historical_anchor' => (bool) $result->is_historical_anchor],
            );

            return $result;
        });
    }

    private function createAutoLine(
        DailyOperationDay $day,
        int $sortOrder,
        string $section,
        string $counterpartyName,
        int $pallets,
        string $observations,
        string $sourceType,
        ?int $sourceId,
        int $userId,
    ): void {
        $day->lines()->create([
            'section' => $section,
            'counterparty_name' => $counterpartyName,
            'pallets' => $pallets,
            'observations' => $observations,
            'without_booking' => false,
            'is_auto_generated' => true,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'parent_line_id' => null,
            'sort_order' => $sortOrder,
            'created_by' => $userId,
        ]);
    }
}
