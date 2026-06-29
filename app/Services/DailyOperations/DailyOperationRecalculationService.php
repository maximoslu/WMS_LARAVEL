<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\GoodsDispatch;
use App\Models\GoodsReceipt;
use App\Models\StockPallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyOperationRecalculationService
{
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

            $manualSortOrder = (int) $day->lines()->where('is_auto_generated', false)->max('sort_order');

            $day->lines()->where('is_auto_generated', true)->delete();

            $sortOrder = $manualSortOrder;
            $storedToday = 0;
            $movedToday = 0;

            $receipts = GoodsReceipt::query()
                ->with(['supplier', 'lines'])
                ->where('client_id', $clientId)
                ->where('status', GoodsReceipt::STATUS_CONFIRMED)
                ->whereDate('received_at', $date)
                ->orderBy('id')
                ->get();

            foreach ($receipts as $receipt) {
                $receiptPallets = max(0, (int) $receipt->lines->sum('pallet_count'));
                $storedToday += $receiptPallets;

                if ($receiptPallets > 0) {
                    $this->createAutoLine(
                        $day,
                        ++$sortOrder,
                        DailyOperationLine::SECTION_DESCARGA,
                        (string) ($receipt->supplier?->name ?? 'Entrada confirmada'),
                        $receiptPallets,
                        'Entrada '.$receipt->receipt_number.' confirmada.',
                        'goods_receipt',
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
                    'goods_receipt',
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

                $movedToday += $dispatchPallets;

                if ($dispatchPallets > 0) {
                    $this->createAutoLine(
                        $day,
                        ++$sortOrder,
                        DailyOperationLine::SECTION_CARGA,
                        (string) ($dispatch->client?->name ?? 'Salida'),
                        $dispatchPallets,
                        'Salida '.$dispatch->dispatchNumber().' en estado '.$dispatch->status.'.',
                        'goods_dispatch',
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
                    'Gestion de camion asociada a la salida '.$dispatch->dispatchNumber().'.',
                    'goods_dispatch',
                    (int) $dispatch->id,
                    $userId,
                );

                $this->createAutoLine(
                    $day,
                    ++$sortOrder,
                    DailyOperationLine::SECTION_VIAJE_CAMION,
                    (string) ($dispatch->client?->name ?? 'Salida'),
                    1,
                    'Viaje de camion asociado a la salida '.$dispatch->dispatchNumber().'.',
                    'goods_dispatch',
                    (int) $dispatch->id,
                    $userId,
                );
            }

            $activeStockPallets = (int) StockPallet::query()
                ->where('client_id', $clientId)
                ->where('active', true)
                ->where('status', StockPallet::STATUS_AVAILABLE)
                ->count();

            if ($activeStockPallets > 0) {
                $this->createAutoLine(
                    $day,
                    ++$sortOrder,
                    DailyOperationLine::SECTION_ALMACENAJE,
                    'Stock activo del cliente',
                    $activeStockPallets,
                    'Base estimada de almacenaje para la fecha '.$date.'.',
                    'stock_snapshot',
                    null,
                    $userId,
                );
            }

            $day->fill([
                'opening_pallets' => max(0, $activeStockPallets - $storedToday),
                'stored_pallets_today' => $storedToday,
                'moved_pallets_today' => $movedToday,
                'expected_pallets_tomorrow' => $activeStockPallets,
                'updated_by' => $userId,
            ])->save();

            return $day->load(['client', 'creator', 'updater', 'lines.creator']);
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
            'sort_order' => $sortOrder,
            'created_by' => $userId,
        ]);
    }
};
