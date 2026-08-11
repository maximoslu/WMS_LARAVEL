<?php

namespace App\Console\Commands;

use App\Models\StockPallet;
use App\Support\Stock\LotNormalizer;
use App\Support\Stock\StockBatchIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConsolidateStockBatchesCommand extends Command
{
    protected $signature = 'wms:consolidate-stock-batches
        {--apply : Aplica las consolidaciones compatibles}
        {--dry-run : Muestra las consolidaciones sin guardar cambios}
        {--client= : Limita la auditoria a un cliente}
        {--sku= : Limita la auditoria a un SKU}';

    protected $description = 'Audita y consolida partidas activas con la misma identidad fisica de stock.';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('No se pueden usar --apply y --dry-run a la vez.');

            return self::INVALID;
        }

        $candidates = $this->candidateGroups();

        if ($candidates->isEmpty()) {
            $this->info('No hay partidas duplicadas compatibles para consolidacion.');

            return self::SUCCESS;
        }

        foreach ($candidates as $candidate) {
            $this->line(sprintf(
                'ids=%s cliente=%d item=%d lote=%s ubicacion=%s estado=%s categoria=%s filas=%d unidades=%d pales=%.2f%s',
                $candidate['rows']->pluck('id')->implode(','),
                $candidate['client_id'],
                $candidate['item_id'],
                $candidate['lot'] ?: 'NO LOTE',
                $candidate['location_label'] ?: 'sin-ubicacion',
                $candidate['status'],
                $candidate['stock_category'],
                $candidate['rows']->count(),
                $candidate['quantity_units'],
                $candidate['warehouse_pallets'],
                $candidate['conflict'] !== null ? ' conflicto='.$candidate['conflict'] : '',
            ));
        }

        if (! $this->option('apply')) {
            $this->info('Dry-run completado. No se ha modificado ninguna partida ni movimiento.');

            return self::SUCCESS;
        }

        $mergeable = $candidates->filter(fn (array $candidate): bool => $candidate['conflict'] === null);

        DB::transaction(function () use ($mergeable): void {
            foreach ($mergeable as $candidate) {
                $this->consolidateCandidate($candidate);
            }
        });

        $this->info(sprintf('Consolidacion completada correctamente. Grupos aplicados: %d.', $mergeable->count()));

        return self::SUCCESS;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function candidateGroups(): Collection
    {
        return StockPallet::query()
            ->with(['item', 'location.warehouse'])
            ->where('active', true)
            ->whereHas('item')
            ->when($this->option('client'), fn ($query, $clientId) => $query->where('client_id', (int) $clientId))
            ->when($this->option('sku'), fn ($query, $sku) => $query->whereHas('item', fn ($itemQuery) => $itemQuery->where('sku', $sku)))
            ->orderBy('id')
            ->get()
            ->groupBy(fn (StockPallet $row): string => $this->identityKey($row))
            ->map(fn (Collection $rows): ?array => $this->describeCandidate($rows))
            ->filter()
            ->values();
    }

    private function identityKey(StockPallet $row): string
    {
        return StockBatchIdentity::fromStockPallet($row)->hash();
    }

    /** @return array<string, mixed>|null */
    private function describeCandidate(Collection $rows): ?array
    {
        if ($rows->count() < 2) {
            return null;
        }

        /** @var StockPallet $first */
        $first = $rows->first();
        $peaks = $rows
            ->flatMap(fn (StockPallet $row): Collection => collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
                ->map(fn (int $number): int => (int) ($row->{'peak_'.$number} ?? 0))
                ->filter(fn (int $value): bool => $value > 0))
            ->values()
            ->all();

        return [
            'client_id' => (int) $first->client_id,
            'item_id' => (int) $first->item_id,
            'lot' => LotNormalizer::normalize($first->lot),
            'location_label' => $first->location?->displayLabel() ?? trim((string) $first->location_text),
            'status' => (string) ($first->status ?? StockPallet::STATUS_AVAILABLE),
            'stock_category' => (string) ($first->stock_category ?? StockPallet::CATEGORY_IN_USE),
            'units_per_pallet' => (int) $first->units_per_pallet,
            'quantity_units' => (int) $rows->sum(fn (StockPallet $row): int => (int) $row->quantity_units),
            'warehouse_pallets' => (float) $rows->sum(fn (StockPallet $row): float => (float) ($row->warehouse_pallets ?? 0)),
            'peaks' => $peaks,
            'conflict' => count($peaks) > StockPallet::MAX_PEAK_COLUMNS ? 'mas de 10 picos' : null,
            'rows' => $rows->values(),
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function consolidateCandidate(array $candidate): void
    {
        /** @var Collection<int, StockPallet> $candidateRows */
        $candidateRows = $candidate['rows'];
        $ids = $candidateRows->pluck('id')->all();
        $rows = StockPallet::query()
            ->whereIn('id', $ids)
            ->where('active', true)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        if ($rows->count() < 2) {
            return;
        }

        $peaks = $rows
            ->flatMap(fn (StockPallet $row): Collection => collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
                ->map(fn (int $number): int => (int) ($row->{'peak_'.$number} ?? 0))
                ->filter(fn (int $value): bool => $value > 0))
            ->values()
            ->all();

        if (count($peaks) > StockPallet::MAX_PEAK_COLUMNS) {
            return;
        }

        /** @var StockPallet $keeper */
        $keeper = $rows->first();
        $duplicateIds = $rows->slice(1)->pluck('id')->all();

        $keeper->forceFill([
            'pallet_code' => null,
            'quantity_units' => (int) $rows->sum(fn (StockPallet $row): int => (int) $row->quantity_units),
            'warehouse_pallets' => (float) $rows->sum(fn (StockPallet $row): float => (float) ($row->warehouse_pallets ?? 0)),
            ...collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
                ->mapWithKeys(fn (int $number): array => ['peak_'.$number => $peaks[$number - 1] ?? 0])
                ->all(),
            'notes' => $this->appendNote($keeper->notes, 'Partida consolidada desde IDs: '.implode(', ', $duplicateIds).'.'),
        ])->save();

        DB::table('inventory_movements')
            ->whereIn('stock_pallet_id', $duplicateIds)
            ->update(['stock_pallet_id' => $keeper->id]);

        StockPallet::query()
            ->whereIn('id', $duplicateIds)
            ->update([
                'active' => false,
                'status' => StockPallet::STATUS_OBSOLETE,
                'blocked_reason' => 'Partida consolidada en la partida #'.$keeper->id,
                'notes' => 'Historica: consolidada en la partida #'.$keeper->id,
                'updated_at' => now(),
            ]);
    }

    private function appendNote(?string $currentNotes, string $note): string
    {
        $currentNotes = trim((string) $currentNotes);

        return $currentNotes === '' ? $note : $currentNotes."\n".$note;
    }
}
