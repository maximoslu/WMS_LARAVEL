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
     *     allocations:array<int, array{
     *         stock_pallet_id:int|null,
     *         loaded_pallets:int,
     *         loaded_partial_units:int,
     *         selected_peaks:array<int, array{index:int, units:int}>,
     *         lot:string|null,
     *         location_text:string|null
     *     }>,
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
                    'allocations' => $payload['allocations'] ?? null,
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
            'return_to_request' => ['nullable', 'boolean'],
            'finalize_dispatch' => ['nullable', 'boolean'],
            'camion_propio' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['nullable', 'integer'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.line_type' => ['nullable', 'string'],
            'lines.*.stock_pallet_id' => ['nullable', 'integer'],
            'lines.*.stock_peak_index' => ['nullable', 'integer', 'min:1'],
            'lines.*.loaded_quantity' => ['nullable', 'integer', 'min:0'],
            'lines.*.loaded_pallets' => ['nullable', 'integer', 'min:0'],
            'lines.*.loaded_partial_units' => ['nullable', 'integer', 'min:0'],
            'lines.*.allocations' => ['nullable', 'array'],
            'lines.*.allocations.*.stock_pallet_id' => ['nullable', 'integer'],
            'lines.*.allocations.*.loaded_pallets' => ['nullable', 'integer', 'min:0'],
            'lines.*.allocations.*.loaded_partial_units' => ['nullable', 'integer', 'min:0'],
            'lines.*.allocations.*.selected_peak_indices' => ['nullable', 'array'],
            'lines.*.allocations.*.selected_peak_indices.*' => ['integer', 'min:1'],
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
            $hasPositiveLoadedLine = collect($lines)->contains(
                fn (array $line): bool => ! $line['remove']
                    && (((int) $line['loaded_pallets'] + (int) $line['loaded_partial_units']) > 0)
            );

            if (! $hasPositiveLoadedLine && $this->resolvedErrors() === [] && $validator->errors()->isEmpty()) {
                $validator->errors()->add('lines', 'Debes confirmar al menos una linea con carga real mayor que cero.');
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
     *     allocations:array<int, array{
     *         stock_pallet_id:int|null,
     *         loaded_pallets:int,
     *         loaded_partial_units:int,
     *         selected_peaks:array<int, array{index:int, units:int}>,
     *         lot:string|null,
     *         location_text:string|null
     *     }>,
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

                $lineAllocations = $this->resolveAllocationsForLine(
                    $payload,
                    $line,
                    $dispatch,
                    $rowKey,
                    $errors,
                    $submittedLoadedPallets,
                    $loadedQuantity,
                    $loadedPartialUnits,
                );

                if ($lineAllocations === null) {
                    continue;
                }

                $loadedPallets = (int) collect($lineAllocations)->sum('loaded_pallets');
                $loadedPartialUnits = (int) collect($lineAllocations)->sum('loaded_partial_units');
                $loadedPeaks = (int) collect($lineAllocations)->sum(fn (array $allocation): int => count($allocation['selected_peaks']));
                $stockPalletId = $lineAllocations[0]['stock_pallet_id'] ?? $line->stock_pallet_id;
                $stockPeakIndex = $lineAllocations[0]['selected_peaks'][0]['index'] ?? $line->stock_peak_index;

                $resolvedLines[] = [
                    'line_id' => $lineId,
                    'item_id' => $line->item_id,
                    'stock_pallet_id' => $stockPalletId,
                    'line_type' => $line->lineType(),
                    'stock_peak_index' => $stockPeakIndex,
                    'lot' => $lineAllocations[0]['lot'] ?? $line->lot,
                    'units_per_pallet' => $line->units_per_pallet,
                    'units_per_peak' => $line->units_per_peak,
                    'loaded_pallets' => $loadedPallets,
                    'loaded_peaks' => $loadedPeaks,
                    'loaded_partial_units' => $loadedPartialUnits,
                    'allocations' => $lineAllocations,
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
                    'allocations' => [[
                        'stock_pallet_id' => $stockPallet->id,
                        'loaded_pallets' => 0,
                        'loaded_partial_units' => $loadedPartialUnits,
                        'selected_peaks' => $loadedPartialUnits > 0 ? [[
                            'index' => $stockPeakIndex,
                            'units' => $unitsPerPeak,
                        ]] : [],
                        'lot' => filled($stockPallet->lot) ? trim((string) $stockPallet->lot) : null,
                        'location_text' => filled($stockPallet->location_text) ? trim((string) $stockPallet->location_text) : null,
                    ]],
                    'loading_notes' => $loadingNotes,
                    'remove' => false,
                ];

                continue;
            }

            $loadedPallets = max(0, $submittedLoadedPallets ?? $loadedQuantity);

            if ($loadedPartialUnits > 0) {
                if (! $stockPallet instanceof StockPallet) {
                    $errors["lines.$rowKey.stock_pallet_id"] = 'Selecciona una partida concreta para cargar unidades parciales.';
                    continue;
                }

                if (! $this->loadingFitsStock($stockPallet, $loadedPallets, $loadedPartialUnits, null)) {
                    $errors["lines.$rowKey.stock_pallet_id"] = 'La carga real supera el stock disponible en la partida seleccionada.';
                    continue;
                }
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
                'allocations' => $stockPallet instanceof StockPallet ? [[
                    'stock_pallet_id' => $stockPallet->id,
                    'loaded_pallets' => $loadedPallets,
                    'loaded_partial_units' => $loadedPartialUnits,
                    'selected_peaks' => [],
                    'lot' => filled($stockPallet->lot) ? trim((string) $stockPallet->lot) : null,
                    'location_text' => filled($stockPallet->location_text) ? trim((string) $stockPallet->location_text) : null,
                ]] : [],
                'loading_notes' => $loadingNotes,
                'remove' => false,
            ];
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $errors
     * @return array<int, array{
     *     stock_pallet_id:int|null,
     *     loaded_pallets:int,
     *     loaded_partial_units:int,
     *     selected_peaks:array<int, array{index:int, units:int}>,
     *     lot:string|null,
     *     location_text:string|null
     * }> | null
     */
    private function resolveAllocationsForLine(
        array $payload,
        GoodsDispatchLine $line,
        GoodsDispatch $dispatch,
        string $rowKey,
        array &$errors,
        ?int $submittedLoadedPallets,
        int $loadedQuantity,
        int $loadedPartialUnits,
    ): ?array {
        $rawAllocations = collect(is_array($payload['allocations'] ?? null) ? $payload['allocations'] : [])
            ->filter(fn ($allocation): bool => is_array($allocation))
            ->values();

        if ($rawAllocations->isEmpty()) {
            $rawAllocations = collect([[
                'stock_pallet_id' => $payload['stock_pallet_id'] ?? $line->stock_pallet_id,
                'loaded_pallets' => $line->isPalletLine() ? ($submittedLoadedPallets ?? $loadedQuantity) : 0,
                'loaded_partial_units' => $loadedPartialUnits,
                'selected_peak_indices' => $line->isPeakLine() && ($payload['stock_peak_index'] ?? $line->stock_peak_index)
                    ? [$payload['stock_peak_index'] ?? $line->stock_peak_index]
                    : [],
            ]]);
        }

        $stockPalletIds = $rawAllocations
            ->pluck('stock_pallet_id')
            ->filter(fn ($stockPalletId): bool => is_numeric((string) $stockPalletId) && (int) $stockPalletId > 0)
            ->map(fn ($stockPalletId): int => (int) $stockPalletId)
            ->unique()
            ->values()
            ->all();

        $stockPallets = StockPallet::query()
            ->where('client_id', $dispatch->client_id)
            ->where('item_id', $line->item_id)
            ->where('active', true)
            ->where('status', StockPallet::STATUS_AVAILABLE)
            ->whereIn('id', $stockPalletIds)
            ->get()
            ->keyBy('id');

        $resolved = [];
        $usedPalletsByStock = [];
        $usedPartialUnitsByStock = [];
        $usedPeakKeys = [];

        foreach ($rawAllocations as $allocationIndex => $allocationPayload) {
            $allocationPayload = is_array($allocationPayload) ? $allocationPayload : [];
            $stockPalletId = is_numeric((string) ($allocationPayload['stock_pallet_id'] ?? null))
                ? (int) $allocationPayload['stock_pallet_id']
                : null;
            $loadedPallets = $line->isPalletLine() && is_numeric((string) ($allocationPayload['loaded_pallets'] ?? null))
                ? max(0, (int) $allocationPayload['loaded_pallets'])
                : 0;
            $manualPartialUnits = is_numeric((string) ($allocationPayload['loaded_partial_units'] ?? null))
                ? max(0, (int) $allocationPayload['loaded_partial_units'])
                : 0;

            $selectedPeakIndices = $this->selectedPeakIndices($allocationPayload);

            if ($selectedPeakIndices !== [] && count($selectedPeakIndices) !== count(array_unique($selectedPeakIndices))) {
                $errors["lines.$rowKey.allocations.$allocationIndex.selected_peak_indices"] = 'No puedes usar el mismo pico dos veces en la misma preparacion.';
                return null;
            }

            if ($stockPalletId === null && ($manualPartialUnits > 0 || $selectedPeakIndices !== [])) {
                $errors["lines.$rowKey.allocations.$allocationIndex.stock_pallet_id"] = 'Selecciona una partida concreta para esta asignacion.';
                return null;
            }

            $stockPallet = $stockPalletId !== null ? $stockPallets->get($stockPalletId) : null;

            if ($stockPalletId !== null && ! $stockPallet instanceof StockPallet) {
                $errors["lines.$rowKey.allocations.$allocationIndex.stock_pallet_id"] = 'La partida seleccionada no coincide con la referencia elegida.';
                return null;
            }

            $selectedPeaks = [];
            $selectedPeakUnits = 0;

            foreach ($selectedPeakIndices as $peakIndex) {
                if (! $stockPallet instanceof StockPallet) {
                    $errors["lines.$rowKey.allocations.$allocationIndex.selected_peak_indices"] = 'Selecciona una partida antes de elegir picos.';
                    return null;
                }

                if ($peakIndex < 1 || $peakIndex > StockPallet::MAX_PEAK_COLUMNS) {
                    $errors["lines.$rowKey.allocations.$allocationIndex.selected_peak_indices"] = 'El pico seleccionado no es valido.';
                    return null;
                }

                $peakKey = $stockPallet->id.':'.$peakIndex;

                if (isset($usedPeakKeys[$peakKey])) {
                    $errors["lines.$rowKey.allocations.$allocationIndex.selected_peak_indices"] = 'No puedes usar el mismo pico dos veces en la misma preparacion.';
                    return null;
                }

                $peakUnits = max(0, (int) ($stockPallet->{'peak_'.$peakIndex} ?? 0));

                if ($peakUnits <= 0) {
                    $errors["lines.$rowKey.allocations.$allocationIndex.selected_peak_indices"] = 'El pico seleccionado ya no esta disponible.';
                    return null;
                }

                $usedPeakKeys[$peakKey] = true;
                $selectedPeaks[] = [
                    'index' => $peakIndex,
                    'units' => $peakUnits,
                ];
                $selectedPeakUnits += $peakUnits;
            }

            $allocationPartialUnits = $manualPartialUnits + $selectedPeakUnits;

            if ($loadedPallets <= 0 && $allocationPartialUnits <= 0) {
                continue;
            }

            if ($stockPallet instanceof StockPallet) {
                $stockId = (int) $stockPallet->id;
                $usedPalletsByStock[$stockId] = ($usedPalletsByStock[$stockId] ?? 0) + $loadedPallets;
                $usedPartialUnitsByStock[$stockId] = ($usedPartialUnitsByStock[$stockId] ?? 0) + $allocationPartialUnits;

                if (! $this->loadingFitsStock($stockPallet, $usedPalletsByStock[$stockId], $usedPartialUnitsByStock[$stockId], null)) {
                    $errors["lines.$rowKey.allocations.$allocationIndex.stock_pallet_id"] = 'La carga real supera el stock disponible en la partida seleccionada.';
                    return null;
                }
            }

            $resolved[] = [
                'stock_pallet_id' => $stockPallet?->id,
                'loaded_pallets' => $loadedPallets,
                'loaded_partial_units' => $allocationPartialUnits,
                'selected_peaks' => $selectedPeaks,
                'lot' => filled($stockPallet?->lot) ? trim((string) $stockPallet->lot) : null,
                'location_text' => filled($stockPallet?->location_text) ? trim((string) $stockPallet?->location_text) : null,
            ];
        }

        if ($resolved === [] && $line->isPeakLine() && $loadedQuantity > 0 && $line->stock_peak_index !== null) {
            $fallbackStock = $line->stockPallet;
            $peakUnits = $fallbackStock instanceof StockPallet
                ? max(0, (int) ($fallbackStock->{'peak_'.$line->stock_peak_index} ?? 0))
                : max(0, (int) ($line->units_per_peak ?? 0));

            if ($fallbackStock instanceof StockPallet && $peakUnits > 0) {
                $resolved[] = [
                    'stock_pallet_id' => $fallbackStock->id,
                    'loaded_pallets' => 0,
                    'loaded_partial_units' => $peakUnits,
                    'selected_peaks' => [[
                        'index' => (int) $line->stock_peak_index,
                        'units' => $peakUnits,
                    ]],
                    'lot' => filled($fallbackStock->lot) ? trim((string) $fallbackStock->lot) : null,
                    'location_text' => filled($fallbackStock->location_text) ? trim((string) $fallbackStock->location_text) : null,
                ];
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $allocationPayload
     * @return list<int>
     */
    private function selectedPeakIndices(array $allocationPayload): array
    {
        $indices = $allocationPayload['selected_peak_indices'] ?? [];

        if (! is_array($indices)) {
            return [];
        }

        return collect($indices)
            ->filter(fn ($peakIndex): bool => is_numeric((string) $peakIndex))
            ->map(fn ($peakIndex): int => (int) $peakIndex)
            ->filter(fn (int $peakIndex): bool => $peakIndex > 0)
            ->values()
            ->all();
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
