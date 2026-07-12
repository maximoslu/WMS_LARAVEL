<?php

namespace App\Http\Requests;

use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockPallet;
use App\Support\WmsLineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConfirmGoodsDispatchLoadingRequest extends FormRequest
{
    /**
     * @var array<int, array{
     *     line_id:int|null,
     *     item_id:int|null,
     *     sku?:string,
     *     description?:string,
     *     stock_pallet_id:int|null,
     *     line_type:string,
     *     stock_peak_index:int|null,
     *     lot:string|null,
     *     location_text?:string|null,
     *     units_per_pallet:int|null,
     *     units_per_peak:int|null,
     *     loaded_pallets:int,
     *     loaded_peaks:int,
     *     loaded_partial_units:int,
     *     loading_notes:string|null,
     *     remove:bool
     * }> | null
     */
    private ?array $resolvedLines = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $resolvedErrors = null;

    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $submittedLines = collect($this->input('lines', []))
            ->map(function ($payload) {
                if (! is_array($payload)) {
                    return [];
                }

                return [
                    'line_id' => $payload['line_id'] ?? null,
                    'item_id' => $payload['item_id'] ?? null,
                    'line_type' => $payload['line_type'] ?? null,
                    'stock_pallet_id' => $payload['stock_pallet_id'] ?? null,
                    'stock_peak_index' => $payload['stock_peak_index'] ?? null,
                    'loaded_quantity' => $payload['loaded_quantity'] ?? $payload['loaded_pallets'] ?? null,
                    'loaded_pallets' => $payload['loaded_pallets'] ?? null,
                    'loaded_partial_units' => $payload['loaded_partial_units'] ?? $payload['loaded_units_partial'] ?? null,
                    'loading_notes' => $payload['loading_notes'] ?? null,
                    'remove' => $payload['remove'] ?? null,
                ];
            })
            ->all();

        $this->merge([
            'lines' => $submittedLines,
        ]);
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['nullable', 'integer'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.line_type' => ['nullable', 'string'],
            'lines.*.stock_pallet_id' => ['nullable', 'integer'],
            'lines.*.stock_peak_index' => ['nullable', 'integer', 'min:1'],
            'lines.*.loaded_quantity' => ['nullable', 'integer', 'min:0'],
            'lines.*.loaded_pallets' => ['nullable', 'integer', 'min:0'],
            'lines.*.loaded_partial_units' => ['nullable', 'integer', 'min:0'],
            'lines.*.loading_notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.remove' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $dispatch = $this->dispatch();

            if (! $dispatch instanceof GoodsDispatch) {
                return;
            }

            $lines = $this->validatedLines();

            if ($lines === []) {
                $validator->errors()->add('lines', 'Debes informar al menos una linea de carga real.');
            }

            foreach ($this->resolvedErrors() as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    /**
     * @return array<int, array{
     *     line_id:int|null,
     *     item_id:int|null,
     *     sku?:string,
     *     description?:string,
     *     stock_pallet_id:int|null,
     *     line_type:string,
     *     stock_peak_index:int|null,
     *     lot:string|null,
     *     location_text?:string|null,
     *     units_per_pallet:int|null,
     *     units_per_peak:int|null,
     *     loaded_pallets:int,
     *     loaded_peaks:int,
     *     loaded_partial_units:int,
     *     loading_notes:string|null,
     *     remove:bool
     * }>
     */
    public function validatedLines(): array
    {
        if ($this->resolvedLines !== null) {
            return $this->resolvedLines;
        }

        $dispatch = $this->dispatch();

        if (! $dispatch instanceof GoodsDispatch) {
            return [];
        }

        $dispatch->loadMissing('lines');
        $existingLines = $dispatch->lines->keyBy('id');
        $submittedLines = collect($this->input('lines', []));
        $resolvedLines = [];
        $errors = [];
        $hasPositiveLoadedLine = false;

        $extraRows = $submittedLines
            ->filter(function ($payload): bool {
                return ! is_numeric((string) (is_array($payload) ? ($payload['line_id'] ?? null) : null));
            })
            ->map(fn ($payload) => is_array($payload) ? $payload : []);

        $extraItemIds = $extraRows
            ->pluck('item_id')
            ->filter(fn ($itemId) => is_numeric((string) $itemId) && (int) $itemId > 0)
            ->map(fn ($itemId) => (int) $itemId)
            ->unique()
            ->values()
            ->all();

        $extraStockPalletIds = $extraRows
            ->pluck('stock_pallet_id')
            ->filter(fn ($stockPalletId) => is_numeric((string) $stockPalletId) && (int) $stockPalletId > 0)
            ->map(fn ($stockPalletId) => (int) $stockPalletId)
            ->unique()
            ->values()
            ->all();

        $items = Item::query()
            ->where('client_id', $dispatch->client_id)
            ->where('active', true)
            ->whereIn('id', $extraItemIds)
            ->get()
            ->keyBy('id');

        $stockPallets = StockPallet::query()
            ->where('client_id', $dispatch->client_id)
            ->where('active', true)
            ->where('status', StockPallet::STATUS_AVAILABLE)
            ->whereIn('id', $extraStockPalletIds)
            ->get()
            ->keyBy('id');

        foreach ($submittedLines as $rowKey => $payload) {
            $rowKey = (string) $rowKey;
            $payload = is_array($payload) ? $payload : [];
            $remove = filter_var($payload['remove'] ?? false, FILTER_VALIDATE_BOOL);
            $loadedQuantity = is_numeric((string) ($payload['loaded_quantity'] ?? null))
                ? (int) $payload['loaded_quantity']
                : 0;
            $submittedLoadedPallets = is_numeric((string) ($payload['loaded_pallets'] ?? null))
                ? (int) $payload['loaded_pallets']
                : null;
            $loadedPartialUnits = is_numeric((string) ($payload['loaded_partial_units'] ?? null))
                ? (int) $payload['loaded_partial_units']
                : 0;
            $lineId = is_numeric((string) ($payload['line_id'] ?? null))
                ? (int) $payload['line_id']
                : (preg_match('/^\d+$/', $rowKey) === 1 ? (int) $rowKey : null);
            $loadingNotes = filled($payload['loading_notes'] ?? null)
                ? trim((string) $payload['loading_notes'])
                : null;

            if ($lineId !== null) {
                $line = $existingLines->get($lineId);

                if (! $line instanceof GoodsDispatchLine) {
                    $errors["lines.$rowKey.line_id"] = 'Hay lineas de carga no validas para esta salida.';
                    continue;
                }

                $stockPalletId = is_numeric((string) ($payload['stock_pallet_id'] ?? null))
                    ? (int) $payload['stock_pallet_id']
                    : $line->stock_pallet_id;
                $stockPeakIndex = is_numeric((string) ($payload['stock_peak_index'] ?? null))
                    ? (int) $payload['stock_peak_index']
                    : $line->stock_peak_index;
                $stockPallet = $stockPalletId !== null
                    ? StockPallet::query()
                        ->where('client_id', $dispatch->client_id)
                        ->where('item_id', $line->item_id)
                        ->where('active', true)
                        ->where('status', StockPallet::STATUS_AVAILABLE)
                        ->whereKey($stockPalletId)
                        ->first()
                    : null;

                if ($stockPalletId !== null && ! $stockPallet instanceof StockPallet) {
                    $errors["lines.$rowKey.stock_pallet_id"] = 'La partida seleccionada no coincide con la referencia elegida.';
                    continue;
                }

                $loadedPallets = $line->isPalletLine()
                    ? max(0, $submittedLoadedPallets ?? $loadedQuantity)
                    : 0;
                $loadedPeaks = $line->isPeakLine() && ($loadedPartialUnits > 0 || $loadedQuantity > 0)
                    ? 1
                    : 0;

                if ($line->isPeakLine() && $loadedPartialUnits === 0 && $loadedQuantity > 0) {
                    $loadedPartialUnits = max(0, (int) ($line->units_per_peak ?? 0)) * min(1, $loadedQuantity);
                }

                if ($line->isPeakLine() && $loadedQuantity > 1 && $loadedPartialUnits === 0) {
                    $errors["lines.$rowKey.loaded_quantity"] = 'Una linea de pico solo puede cargarse como 0 o 1.';
                    continue;
                }

                if (! $this->loadingFitsRequested($line, $loadedPallets, $loadedPartialUnits)) {
                    $errors["lines.$rowKey.loaded_partial_units"] = 'La carga real no puede superar las unidades solicitadas.';
                    continue;
                }

                if (! $this->loadingFitsStock($stockPallet, $loadedPallets, $loadedPartialUnits, $stockPeakIndex)) {
                    $errors["lines.$rowKey.stock_pallet_id"] = 'La carga real supera el stock disponible en la partida seleccionada.';
                    continue;
                }

                if (! $remove && (($loadedPallets + $loadedPartialUnits) > 0)) {
                    $hasPositiveLoadedLine = true;
                }

                $resolvedLines[] = [
                    'line_id' => $lineId,
                    'item_id' => $line->item_id,
                    'stock_pallet_id' => $stockPalletId,
                    'line_type' => $line->lineType(),
                    'stock_peak_index' => $stockPeakIndex,
                    'lot' => $stockPallet instanceof StockPallet && filled($stockPallet->lot) ? trim((string) $stockPallet->lot) : $line->lot,
                    'units_per_pallet' => $line->units_per_pallet,
                    'units_per_peak' => $line->units_per_peak,
                    'loaded_pallets' => $loadedPallets,
                    'loaded_peaks' => $loadedPeaks,
                    'loaded_partial_units' => $loadedPartialUnits,
                    'loading_notes' => $loadingNotes,
                    'remove' => $remove,
                ];

                continue;
            }

            if ($remove) {
                continue;
            }

            $itemId = is_numeric((string) ($payload['item_id'] ?? null))
                ? (int) $payload['item_id']
                : 0;
            $lineType = in_array($payload['line_type'] ?? null, WmsLineType::values(), true)
                ? (string) $payload['line_type']
                : WmsLineType::PALLET;
            $stockPalletId = is_numeric((string) ($payload['stock_pallet_id'] ?? null))
                ? (int) $payload['stock_pallet_id']
                : null;
            $stockPeakIndex = is_numeric((string) ($payload['stock_peak_index'] ?? null))
                ? (int) $payload['stock_peak_index']
                : null;

            $item = $items->get($itemId);

            if (! $item instanceof Item) {
                $errors["lines.$rowKey.item_id"] = 'Selecciona una referencia valida para este cliente.';
                continue;
            }

            $stockPallet = $stockPalletId !== null ? $stockPallets->get($stockPalletId) : null;

            if ($stockPalletId !== null && (! $stockPallet instanceof StockPallet || (int) $stockPallet->item_id !== $item->id)) {
                $errors["lines.$rowKey.stock_pallet_id"] = 'La partida seleccionada no coincide con la referencia elegida.';
                continue;
            }

            if ($lineType === WmsLineType::PEAK) {
                if (! $stockPallet instanceof StockPallet) {
                    $errors["lines.$rowKey.stock_pallet_id"] = 'Selecciona un pico existente para esta referencia.';
                    continue;
                }

                if ($stockPeakIndex === null || $stockPeakIndex < 1 || $stockPeakIndex > StockPallet::MAX_PEAK_COLUMNS) {
                    $errors["lines.$rowKey.stock_peak_index"] = 'El pico seleccionado no es valido.';
                    continue;
                }

                $unitsPerPeak = max(0, (int) ($stockPallet->{'peak_'.$stockPeakIndex} ?? 0));

                if ($unitsPerPeak <= 0) {
                    $errors["lines.$rowKey.stock_peak_index"] = 'El pico seleccionado ya no esta disponible.';
                    continue;
                }

                if ($loadedQuantity > 1) {
                    $errors["lines.$rowKey.loaded_quantity"] = 'Cada linea de pico representa un pico concreto.';
                    continue;
                }

                if ($loadedPartialUnits === 0 && $loadedQuantity > 0) {
                    $loadedPartialUnits = $unitsPerPeak;
                }

                if ($loadedPartialUnits > $unitsPerPeak) {
                    $errors["lines.$rowKey.loaded_partial_units"] = 'La carga parcial supera las unidades disponibles en el pico seleccionado.';
                    continue;
                }

                if ($loadedQuantity > 0 || $loadedPartialUnits > 0) {
                    $hasPositiveLoadedLine = true;
                }

                $resolvedLines[] = [
                    'line_id' => null,
                    'item_id' => $item->id,
                    'sku' => $item->sku,
                    'description' => $item->description,
                    'stock_pallet_id' => $stockPallet->id,
                    'line_type' => WmsLineType::PEAK,
                    'stock_peak_index' => $stockPeakIndex,
                    'lot' => filled($stockPallet->lot) ? trim((string) $stockPallet->lot) : null,
                    'location_text' => filled($stockPallet->location_text) ? trim((string) $stockPallet->location_text) : null,
                    'units_per_pallet' => (int) $item->units_per_pallet,
                    'units_per_peak' => $unitsPerPeak,
                    'loaded_pallets' => 0,
                    'loaded_peaks' => $loadedPartialUnits > 0 ? 1 : 0,
                    'loaded_partial_units' => $loadedPartialUnits,
                    'loading_notes' => $loadingNotes,
                    'remove' => false,
                ];

                continue;
            }

            $loadedPallets = max(0, $submittedLoadedPallets ?? $loadedQuantity);

            if ($loadedPallets > 0) {
                $hasPositiveLoadedLine = true;
            }

            if ($loadedPartialUnits > 0) {
                if (! $stockPallet instanceof StockPallet) {
                    $errors["lines.$rowKey.stock_pallet_id"] = 'Selecciona una partida concreta para cargar unidades parciales.';
                    continue;
                }

                if (! $this->loadingFitsStock($stockPallet, $loadedPallets, $loadedPartialUnits, null)) {
                    $errors["lines.$rowKey.stock_pallet_id"] = 'La carga real supera el stock disponible en la partida seleccionada.';
                    continue;
                }

                $hasPositiveLoadedLine = true;
            }

            $resolvedLines[] = [
                'line_id' => null,
                'item_id' => $item->id,
                'sku' => $item->sku,
                'description' => $item->description,
                'stock_pallet_id' => $stockPallet?->id,
                'line_type' => WmsLineType::PALLET,
                'stock_peak_index' => null,
                'lot' => filled($stockPallet?->lot) ? trim((string) $stockPallet->lot) : null,
                'location_text' => filled($stockPallet?->location_text) ? trim((string) $stockPallet?->location_text) : null,
                'units_per_pallet' => (int) $item->units_per_pallet,
                'units_per_peak' => null,
                'loaded_pallets' => $loadedPallets,
                'loaded_peaks' => 0,
                'loaded_partial_units' => $loadedPartialUnits,
                'loading_notes' => $loadingNotes,
                'remove' => false,
            ];
        }

        if (! $hasPositiveLoadedLine) {
            $errors['lines'] = 'Debes confirmar al menos una linea con carga real mayor que cero.';
        }

        $this->resolvedLines = $resolvedLines;
        $this->resolvedErrors = $errors;

        return $this->resolvedLines;
    }

    /**
     * @return array<string, string>
     */
    private function resolvedErrors(): array
    {
        if ($this->resolvedErrors !== null) {
            return $this->resolvedErrors;
        }

        $this->validatedLines();

        return $this->resolvedErrors ?? [];
    }

    private function loadingFitsRequested(GoodsDispatchLine $line, int $loadedPallets, int $loadedPartialUnits): bool
    {
        if ($line->is_extra_line) {
            return true;
        }

        $requestedUnits = $line->requestedUnitsTotal();

        if ($requestedUnits <= 0) {
            return true;
        }

        $loadedUnits = ($loadedPallets * max(0, (int) ($line->units_per_pallet ?? 0))) + $loadedPartialUnits;

        return $loadedUnits <= $requestedUnits;
    }

    private function loadingFitsStock(?StockPallet $stockPallet, int $loadedPallets, int $loadedPartialUnits, ?int $preferredPeakIndex): bool
    {
        if ($loadedPallets <= 0 && $loadedPartialUnits <= 0) {
            return true;
        }

        if (! $stockPallet instanceof StockPallet) {
            return $loadedPartialUnits <= 0;
        }

        $availablePallets = max(0, (int) $stockPallet->full_pallets);

        if ($loadedPallets > $availablePallets) {
            return false;
        }

        $remainingFullPallets = $availablePallets - $loadedPallets;
        $availablePeakUnits = $this->availablePeakUnits($stockPallet, $preferredPeakIndex);
        $unitsPerPallet = max(0, (int) $stockPallet->units_per_pallet);
        $availablePartialUnits = $availablePeakUnits + ($remainingFullPallets * $unitsPerPallet);

        return $loadedPartialUnits <= $availablePartialUnits;
    }

    private function availablePeakUnits(StockPallet $stockPallet, ?int $preferredPeakIndex = null): int
    {
        if ($preferredPeakIndex !== null) {
            return max(0, (int) ($stockPallet->{'peak_'.$preferredPeakIndex} ?? 0));
        }

        $total = 0;

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakIndex) {
            $total += max(0, (int) ($stockPallet->{'peak_'.$peakIndex} ?? 0));
        }

        return $total;
    }

    private function dispatch(): GoodsDispatch|null
    {
        $dispatch = $this->route('goodsDispatch');

        return $dispatch instanceof GoodsDispatch ? $dispatch : null;
    }
}
