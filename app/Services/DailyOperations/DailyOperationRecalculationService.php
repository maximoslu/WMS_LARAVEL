<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\GoodsDispatch;
use App\Models\GoodsReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyOperationRecalculationService
{
    public function __construct(
        private readonly DailyOperationTotalsService $totalsService,
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
            $receipts = GoodsReceipt::query()
                ->with(['supplier', 'lines'])
                ->where('client_id', $clientId)
                ->where('status', GoodsReceipt::STATUS_CONFIRMED)
                ->whereDate('received_at', $date)
                ->orderBy('id')
                ->get();

            foreach ($receipts as $receipt) {
                $receiptPallets = max(0, (int) $receipt->lines->sum('pallet_count'));

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
                    'Gestión de camión asociada a la entrada '.$receipt->receipt_number.'.',
                    DailyOperationLine::SOURCE_GOODS_RECEIPT,
                    (int) $receipt->id,
                    $userId,
                );
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
                $dispatchPallets = max(0, $dispatch->loadedPalletsCount());

                if ($dispatchPallets === 0) {
                    $dispatchPallets = max(0, $dispatch->palletsCount());
                }

                if ($dispatchPallets > 0) {
                    $this->createAutoLine(
                        $day,
                        ++$sortOrder,
                        DailyOperationLine::SECTION_ENVIO,
                        (string) ($dispatch->client?->name ?? 'Salida'),
                        $dispatchPallets,
                        'Envío '.$dispatch->dispatchNumber().' en estado '.$dispatch->status.'.',
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
                    'Gestión de camión asociada al envío '.$dispatch->dispatchNumber().'.',
                    DailyOperationLine::SOURCE_GOODS_DISPATCH,
                    (int) $dispatch->id,
                    $userId,
                );

                $this->createAutoLine(
                    $day,
                    ++$sortOrder,
                    DailyOperationLine::SECTION_VIAJE_CAMION,
                    (string) ($dispatch->client?->name ?? 'Salida'),
                    1,
                    'Viaje de camión asociado al envío '.$dispatch->dispatchNumber().'.',
                    DailyOperationLine::SOURCE_GOODS_DISPATCH,
                    (int) $dispatch->id,
                    $userId,
                );
            }

            $stockBase = $this->totalsService->stockBaseForClient($clientId);

            if ($stockBase > 0) {
                $this->createAutoLine(
                    $day,
                    ++$sortOrder,
                    DailyOperationLine::SECTION_ALMACENAJE,
                    'Stock base del cliente',
                    $stockBase,
                    'Base facturable de almacenaje para la fecha '.$date.'.',
                    DailyOperationLine::SOURCE_STOCK_SNAPSHOT,
                    null,
                    $userId,
                );
            }

            return $this->totalsService->syncDay($day, null, $day->notes, $userId);
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
