<?php

namespace App\Console\Commands;

use App\Models\StockPallet;
use App\Support\Stock\StockBatchCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConsolidateStockBatchesCommand extends Command
{
    protected $signature = 'wms:consolidate-stock-batches {--dry-run : Muestra las consolidaciones sin guardar cambios}';

    protected $description = 'Consolida lineas historicas de stock creadas una por pallet en una sola partida agregada por lote.';

    public function handle(): int
    {
        $candidates = $this->candidateGroups();

        if ($candidates->isEmpty()) {
            $this->info('No hay lineas candidatas a consolidacion.');

            return self::SUCCESS;
        }

        foreach ($candidates as $candidate) {
            $this->line(sprintf(
                'Grupo receipt=%s item=%d lote=%s fecha=%s ubicacion=%s filas=%d total=%d uds/pallet=%d',
                $candidate['goods_receipt_id'] !== null ? (string) $candidate['goods_receipt_id'] : 'sin-recepcion',
                $candidate['item_id'],
                $candidate['lot'] ?: 'sin-lote',
                $candidate['received_at'] ?: 'sin-fecha',
                $candidate['location_label'] ?: 'sin-ubicacion',
                $candidate['rows']->count(),
                $candidate['quantity_units'],
                $candidate['units_per_pallet'],
            ));
        }

        if ($this->option('dry-run')) {
            $this->info('Dry-run completado. No se ha modificado ninguna linea.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($candidates): void {
            foreach ($candidates as $candidate) {
                $this->consolidateCandidate($candidate);
            }
        });

        $this->info('Consolidacion completada correctamente.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{
     *     goods_receipt_id: int|null,
     *     item_id: int,
     *     lot: string|null,
     *     received_at: string|null,
     *     location_id: int|null,
     *     location_text: string|null,
     *     location_label: string|null,
     *     client_id: int,
     *     status: string,
     *     blocked_reason: string|null,
     *     units_per_pallet: int,
     *     quantity_units: int,
     *     rows: Collection<int, StockPallet>
     * }>
     */
    private function candidateGroups(): Collection
    {
        return StockPallet::query()
            ->with('item')
            ->where('active', true)
            ->whereNotNull('goods_receipt_id')
            ->orderBy('id')
            ->get()
            ->groupBy(function (StockPallet $row): string {
                return implode('|', [
                    $row->client_id,
                    $row->item_id,
                    $row->goods_receipt_id,
                    $row->lot ?? '',
                    optional($row->received_at)->format('Y-m-d') ?? '',
                    $row->location_id ?? '',
                    $row->location_text ?? '',
                    $row->status ?? '',
                    $row->blocked_reason ?? '',
                ]);
            })
            ->map(function (Collection $rows): ?array {
                if ($rows->count() < 2) {
                    return null;
                }

                $unitsPerPallet = (int) $rows->pluck('units_per_pallet')
                    ->filter(fn (mixed $value): bool => (int) $value > 0)
                    ->first();

                if ($unitsPerPallet <= 0) {
                    $unitsPerPallet = (int) ($rows->first()?->item?->units_per_pallet ?? 0);
                }

                if ($unitsPerPallet <= 0) {
                    return null;
                }

                $hasLegacyPalletRows = $rows->contains(fn (StockPallet $row): bool => filled($row->pallet_code))
                    || $rows->every(fn (StockPallet $row): bool => (int) $row->quantity_units <= $unitsPerPallet);

                if (! $hasLegacyPalletRows) {
                    return null;
                }

                /** @var StockPallet $first */
                $first = $rows->first();

                return [
                    'goods_receipt_id' => $first->goods_receipt_id,
                    'item_id' => $first->item_id,
                    'lot' => $first->lot,
                    'received_at' => optional($first->received_at)->format('Y-m-d'),
                    'location_id' => $first->location_id,
                    'location_text' => $first->location_text,
                    'location_label' => $first->location?->code ?? $first->location_text,
                    'client_id' => $first->client_id,
                    'status' => $first->status,
                    'blocked_reason' => $first->blocked_reason,
                    'units_per_pallet' => $unitsPerPallet,
                    'quantity_units' => (int) $rows->sum('quantity_units'),
                    'rows' => $rows->values(),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array{
     *     goods_receipt_id: int|null,
     *     item_id: int,
     *     lot: string|null,
     *     received_at: string|null,
     *     location_id: int|null,
     *     location_text: string|null,
     *     client_id: int,
     *     status: string,
     *     blocked_reason: string|null,
     *     units_per_pallet: int,
     *     quantity_units: int,
     *     rows: Collection<int, StockPallet>
     * }  $candidate
     */
    private function consolidateCandidate(array $candidate): void
    {
        $breakdown = StockBatchCalculator::calculateBreakdown($candidate['quantity_units'], $candidate['units_per_pallet']);

        /** @var StockPallet $keeper */
        $keeper = $candidate['rows']->first();

        $keeper->forceFill([
            'pallet_code' => null,
            'quantity_units' => $candidate['quantity_units'],
            'units_per_pallet' => $candidate['units_per_pallet'],
            'full_pallets' => $breakdown['full_pallets'],
            'peaks_count' => $breakdown['peaks_count'],
            'peak_1' => $breakdown['peak_1'],
            'peak_2' => $breakdown['peak_2'],
            'peak_3' => $breakdown['peak_3'],
            'peak_4' => $breakdown['peak_4'],
            'peak_5' => $breakdown['peak_5'],
            'peak_6' => $breakdown['peak_6'],
            'peak_7' => $breakdown['peak_7'],
            'peak_8' => $breakdown['peak_8'],
            'notes' => $this->appendNote($keeper->notes, 'Partida consolidada desde lineas historicas por pallet.'),
        ])->save();

        $duplicateIds = $candidate['rows']->slice(1)->pluck('id');

        if ($duplicateIds->isNotEmpty()) {
            StockPallet::query()
                ->whereIn('id', $duplicateIds)
                ->update([
                    'active' => false,
                    'status' => StockPallet::STATUS_OBSOLETE,
                    'blocked_reason' => 'Linea legacy consolidada en la partida #'.$keeper->id,
                    'notes' => 'Legacy: consolidada automaticamente en la partida #'.$keeper->id,
                ]);
        }
    }

    private function appendNote(?string $currentNotes, string $note): string
    {
        $currentNotes = trim((string) $currentNotes);

        return $currentNotes === ''
            ? $note
            : $currentNotes."\n".$note;
    }
}
