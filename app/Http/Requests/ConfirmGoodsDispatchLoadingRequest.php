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
            'lines.*.loaded_quantity' => ['required', 'integer', 'min:0'],
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

                if ($line->isPeakLine() && $loadedQuantity > 1) {
                    $errors["lines.$rowKey.loaded_quantity"] = 'Una linea de pico solo puede cargarse como 0 o 1.';
                    continue;
                }

                if (! $remove && $loadedQuantity > 0) {
                    $hasPositiveLoadedLine = true;
                }

                $resolvedLines[] = [
                    'line_id' => $lineId,
                    'item_id' => $line->item_id,
                    'stock_pallet_id' => $line->stock_pallet_id,
                    'line_type' => $line->lineType(),
                    'stock_peak_index' => $line->stock_peak_index,
                    'lot' => $line->lot,
                    'units_per_pallet' => $line->units_per_pallet,
                    'units_per_peak' => $line->units_per_peak,
                    'loaded_pallets' => $line->isPalletLine() ? $loadedQuantity : 0,
                    'loaded_peaks' => $line->isPeakLine() ? $loadedQuantity : 0,
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

                if ($loadedQuantity > 0) {
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
                    'loaded_peaks' => $loadedQuantity,
                    'loading_notes' => $loadingNotes,
                    'remove' => false,
                ];

                continue;
            }

            if ($loadedQuantity > 0) {
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
                'loaded_pallets' => $loadedQuantity,
                'loaded_peaks' => 0,
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

    private function dispatch(): GoodsDispatch|null
    {
        $dispatch = $this->route('goodsDispatch');

        return $dispatch instanceof GoodsDispatch ? $dispatch : null;
    }
}
