<?php

namespace App\Services\Labels;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\StockPallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MerchandiseLabelService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forGoodsReceipt(GoodsReceipt $receipt): Collection
    {
        $receipt->loadMissing(['client', 'supplier', 'lines.item', 'lines.location.warehouse']);

        return $receipt->lines
            ->flatMap(fn (GoodsReceiptLine $line): Collection => $this->forGoodsReceiptLine($line, false))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forGoodsReceiptLine(GoodsReceiptLine $line, bool $loadMissing = true): Collection
    {
        if ($loadMissing) {
            $line->loadMissing(['goodsReceipt.client', 'goodsReceipt.supplier', 'item', 'location.warehouse']);
        }

        $receipt = $line->goodsReceipt;
        $labels = collect();
        $unitsPerPallet = (int) $line->units_per_pallet;
        $palletCount = max(0, (int) $line->pallet_count);

        if ($palletCount > 0) {
            if ($unitsPerPallet <= 0) {
                throw ValidationException::withMessages([
                    'labels' => 'No se pueden generar etiquetas porque faltan unidades por pallet.',
                ]);
            }

            foreach (range(1, $palletCount) as $number) {
                $labels->push($this->labelPayload(
                    clientName: (string) $receipt->client?->name,
                    clientCode: (string) $receipt->client?->code,
                    sku: $this->sku($line),
                    description: $this->description($line),
                    lot: $this->lot($line->lot),
                    units: $unitsPerPallet,
                    type: 'PALLET',
                    number: 'Pallet '.$number.' de '.$palletCount,
                    receiptNumber: $this->receiptNumber($receipt),
                    receivedAt: $receipt->received_at?->format('d/m/Y'),
                    location: $line->location?->displayLabel(),
                    traceability: 'entrada-linea:'.$line->id.':pallet:'.$number,
                ));
            }
        }

        foreach ($line->peakUnits() as $index => $units) {
            $labels->push($this->labelPayload(
                clientName: (string) $receipt->client?->name,
                clientCode: (string) $receipt->client?->code,
                sku: $this->sku($line),
                description: $this->description($line),
                lot: $this->lot($line->lot),
                units: (int) $units,
                type: 'PICO',
                number: count($line->peakUnits()) > 1 ? 'Pico '.($index + 1) : 'Pico 1',
                receiptNumber: $this->receiptNumber($receipt),
                receivedAt: $receipt->received_at?->format('d/m/Y'),
                location: $line->location?->displayLabel(),
                traceability: 'entrada-linea:'.$line->id.':pico:'.($index + 1),
            ));
        }

        return $this->ensureLabels($labels);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forStockPallet(StockPallet $stockPallet): Collection
    {
        $stockPallet->loadMissing(['client', 'item', 'goodsReceipt', 'location.warehouse']);

        $labels = collect();
        $unitsPerPallet = (int) $stockPallet->units_per_pallet;
        $fullPallets = max(0, (int) $stockPallet->full_pallets);

        if ($fullPallets > 0) {
            if ($unitsPerPallet <= 0) {
                throw ValidationException::withMessages([
                    'labels' => 'No se pueden generar etiquetas porque faltan unidades por pallet.',
                ]);
            }

            foreach (range(1, $fullPallets) as $number) {
                $labels->push($this->labelPayload(
                    clientName: (string) $stockPallet->client?->name,
                    clientCode: (string) $stockPallet->client?->code,
                    sku: (string) $stockPallet->item?->sku,
                    description: (string) $stockPallet->item?->description,
                    lot: $this->lot($stockPallet->lot),
                    units: $unitsPerPallet,
                    type: 'PALLET',
                    number: 'Pallet '.$number.' de '.$fullPallets,
                    receiptNumber: $stockPallet->goodsReceipt ? $this->receiptNumber($stockPallet->goodsReceipt) : null,
                    receivedAt: $stockPallet->received_at?->format('d/m/Y'),
                    location: $stockPallet->pickingLocationLabel(),
                    traceability: 'stock:'.$stockPallet->id.':pallet:'.$number,
                ));
            }
        }

        foreach ($this->stockPeakUnits($stockPallet) as $index => $units) {
            $labels->push($this->labelPayload(
                clientName: (string) $stockPallet->client?->name,
                clientCode: (string) $stockPallet->client?->code,
                sku: (string) $stockPallet->item?->sku,
                description: (string) $stockPallet->item?->description,
                lot: $this->lot($stockPallet->lot),
                units: (int) $units,
                type: 'PICO',
                number: count($this->stockPeakUnits($stockPallet)) > 1 ? 'Pico '.($index + 1) : 'Pico 1',
                receiptNumber: $stockPallet->goodsReceipt ? $this->receiptNumber($stockPallet->goodsReceipt) : null,
                receivedAt: $stockPallet->received_at?->format('d/m/Y'),
                location: $stockPallet->pickingLocationLabel(),
                traceability: 'stock:'.$stockPallet->id.':pico:'.($index + 1),
            ));
        }

        return $this->ensureLabels($labels);
    }

    public function filename(string $clientCode, string $origin): string
    {
        $client = Str::slug($clientCode !== '' ? $clientCode : 'cliente', '_');
        $source = Str::slug($origin !== '' ? $origin : 'etiquetas', '_');

        return sprintf('etiquetas_%s_%s_%s.pdf', $client, $source, now()->format('Ymd'));
    }

    /**
     * @return list<int>
     */
    private function stockPeakUnits(StockPallet $stockPallet): array
    {
        $peaks = collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
            ->map(fn (int $number): int => (int) ($stockPallet->{'peak_'.$number} ?? 0))
            ->filter(fn (int $units): bool => $units > 0)
            ->values()
            ->all();

        if ($peaks !== []) {
            return $peaks;
        }

        $unitsPerPallet = (int) $stockPallet->units_per_pallet;
        $remaining = (int) $stockPallet->quantity_units - (max(0, (int) $stockPallet->full_pallets) * $unitsPerPallet);

        return $unitsPerPallet > 0 && $remaining > 0 ? [$remaining] : [];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $labels
     * @return Collection<int, array<string, mixed>>
     */
    private function ensureLabels(Collection $labels): Collection
    {
        if ($labels->isEmpty()) {
            throw ValidationException::withMessages([
                'labels' => 'No se pueden generar etiquetas porque faltan pallets o picos con unidades.',
            ]);
        }

        return $labels->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function labelPayload(
        string $clientName,
        string $clientCode,
        string $sku,
        string $description,
        string $lot,
        int $units,
        string $type,
        string $number,
        ?string $receiptNumber,
        ?string $receivedAt,
        ?string $location,
        string $traceability,
    ): array {
        return [
            'client_name' => $clientName,
            'client_code' => $clientCode,
            'sku' => $sku,
            'description' => $description,
            'article' => trim($sku.' - '.$description, ' -'),
            'lot' => $lot,
            'units' => $units,
            'type' => $type,
            'number' => $number,
            'receipt_number' => $receiptNumber,
            'received_at' => $receivedAt,
            'location' => $location,
            'traceability' => $traceability,
        ];
    }

    private function sku(GoodsReceiptLine $line): string
    {
        return (string) ($line->item?->sku ?: $line->sku);
    }

    private function description(GoodsReceiptLine $line): string
    {
        return (string) ($line->description ?: $line->item?->description);
    }

    private function lot(?string $lot): string
    {
        return filled($lot) ? (string) $lot : 'SIN LOTE';
    }

    private function receiptNumber(GoodsReceipt $receipt): string
    {
        return $receipt->receipt_number ?: 'Entrada #'.$receipt->id;
    }
}
