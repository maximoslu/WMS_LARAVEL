<?php

namespace App\Http\Requests;

use App\Models\Item;
use App\Models\Role;
use App\Models\Supplier;
use App\Support\Stock\StockBatchCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGoodsReceiptRequest extends FormRequest
{
    public const ACTION_CREATE_DRAFT = 'create_draft';

    public const ACTION_CREATE_AND_EXTRACT_AI = 'create_and_extract_ai';

    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $clientId = $this->normalizeNullableInteger($this->input('client_id'));

        $lines = collect($this->input('lines', []))
            ->map(function (mixed $line) use ($clientId): array {
                $line = is_array($line) ? $line : [];

                $itemId = $this->normalizeNullableInteger($line['item_id'] ?? null);
                $sku = $this->normalizeNullableUpper($line['sku'] ?? null);

                if ($itemId === null && $clientId !== null && $sku !== null) {
                    $existingItemId = Item::query()
                        ->where('client_id', $clientId)
                        ->where('sku', $sku)
                        ->value('id');

                    $itemId = $existingItemId !== null ? (int) $existingItemId : null;
                }

                $item = $itemId !== null ? Item::query()->find($itemId) : null;
                $quantityUnits = $this->normalizeInteger($line['quantity_units'] ?? 0);
                $unitsPerPallet = $this->normalizeNullableInteger($line['units_per_pallet'] ?? null)
                    ?? ($item?->units_per_pallet !== null ? (int) $item->units_per_pallet : null);
                $palletCount = $this->normalizeNullableInteger($line['pallet_count'] ?? null);
                $peaks = $this->normalizePeaks($line);
                $picoUnits = $this->validPeakTotal($peaks);
                $manualPicoUnitsProvided = collect($peaks)->contains(fn (mixed $value): bool => $value !== null && $value !== '');
                $manualPalletCountProvided = array_key_exists('pallet_count', $line)
                    && $line['pallet_count'] !== ''
                    && $line['pallet_count'] !== null
                    && (int) $line['pallet_count'] > 0;

                if ($unitsPerPallet !== null && ($manualPalletCountProvided || $manualPicoUnitsProvided)) {
                    $computedTotal = ((int) ($palletCount ?? 0) * $unitsPerPallet) + (int) ($picoUnits ?? 0);

                    if ($quantityUnits === 0 && $computedTotal > 0) {
                        $quantityUnits = $computedTotal;
                    }
                }

                if ($unitsPerPallet !== null && $quantityUnits > 0 && ! $manualPalletCountProvided && ! $manualPicoUnitsProvided) {
                    $palletCount = StockBatchCalculator::calculateFullPallets($quantityUnits, $unitsPerPallet);
                    $picoUnits = StockBatchCalculator::calculateRemainderPeak($quantityUnits, $unitsPerPallet);
                    $peaks['peak_1'] = $picoUnits > 0 ? $picoUnits : null;
                }

                return array_merge([
                    'item_id' => $itemId,
                    'sku' => $sku ?? $item?->sku,
                    'description' => $this->normalizeNullableText($line['description'] ?? null) ?? $item?->description,
                    'lot' => $this->normalizeNullableUpper($line['lot'] ?? null),
                    'quantity_units' => $quantityUnits,
                    'units_per_pallet' => $unitsPerPallet,
                    'pallet_count' => $palletCount ?? 0,
                    'pico_units' => ($picoUnits ?? 0) > 0 ? $picoUnits : null,
                    'location_id' => $this->normalizeNullableInteger($line['location_id'] ?? null),
                ], $peaks);
            })
            ->filter(function (array $line): bool {
                return $line['item_id'] !== null
                    || $line['sku'] !== null
                    || $line['description'] !== null
                    || $line['lot'] !== null
                    || $line['quantity_units'] > 0
                    || $line['units_per_pallet'] !== null
                    || $line['pallet_count'] > 0
                    || ($line['pico_units'] ?? 0) > 0
                    || collect(range(1, 10))->contains(fn (int $number): bool => filled($line['peak_'.$number] ?? null))
                    || $line['location_id'] !== null;
            })
            ->values()
            ->all();

        $this->merge([
            'action' => $this->normalizeAction($this->input('action')),
            'receipt_number' => $this->normalizeNullableUpper($this->input('receipt_number')),
            'external_document_number' => $this->normalizeNullableUpper($this->input('external_document_number')),
            'notes' => $this->normalizeNullableText($this->input('notes')),
            'camion_propio' => $this->boolean('camion_propio'),
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        $rules = [
            'action' => ['nullable', 'in:'.implode(',', [self::ACTION_CREATE_DRAFT, self::ACTION_CREATE_AND_EXTRACT_AI])],
            'client_id' => ['required', 'exists:clients,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'receipt_number' => ['nullable', 'string', 'max:150'],
            'external_document_number' => ['nullable', 'string', 'max:150'],
            'received_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'camion_propio' => ['boolean'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'lines' => $this->expectsAiCreationFlow()
                ? ['present', 'array']
                : ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'exists:items,id'],
            'lines.*.sku' => ['nullable', 'string', 'max:100'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.lot' => ['nullable', 'string', 'max:100'],
            'lines.*.quantity_units' => ['required', 'integer', 'min:0'],
            'lines.*.units_per_pallet' => ['nullable', 'integer', 'min:1'],
            'lines.*.pallet_count' => ['nullable', 'integer', 'min:0'],
            'lines.*.pico_units' => ['nullable', 'integer', 'min:0'],
            'lines.*.location_id' => ['nullable', 'exists:locations,id'],
        ];

        foreach (range(1, 10) as $peakNumber) {
            $rules['lines.*.peak_'.$peakNumber] = ['nullable', 'integer', 'min:1'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->expectsAiCreationFlow() && count($this->input('lines', [])) === 0) {
                $validator->errors()->add('lines', 'Añade al menos una linea o usa "Crear borrador e interpretar con IA" con un documento adjunto.');

                return;
            }

            $clientId = $this->integer('client_id');
            $supplierId = $this->integer('supplier_id');

            if ($supplierId > 0) {
                $supplierClientId = Supplier::query()->whereKey($supplierId)->value('client_id');

                if ($supplierClientId !== null && (int) $supplierClientId !== $clientId) {
                    $validator->errors()->add('supplier_id', 'El proveedor debe ser global o pertenecer al mismo cliente que la entrada.');
                }
            }

            foreach ($this->input('lines', []) as $index => $line) {
                $itemId = (int) ($line['item_id'] ?? 0);
                $sku = trim((string) ($line['sku'] ?? ''));
                $description = trim((string) ($line['description'] ?? ''));
                $quantityUnits = (int) ($line['quantity_units'] ?? 0);
                $unitsPerPallet = isset($line['units_per_pallet']) ? (int) $line['units_per_pallet'] : null;
                $palletCount = (int) ($line['pallet_count'] ?? 0);
                $picoUnits = collect(range(1, 10))
                    ->sum(fn (int $number): int => (int) ($line['peak_'.$number] ?? 0));

                if ($itemId > 0) {
                    $itemData = Item::query()
                        ->whereKey($itemId)
                        ->first(['client_id', 'active']);

                    if ((int) ($itemData?->client_id ?? 0) !== $clientId) {
                        $validator->errors()->add("lines.$index.item_id", 'El articulo debe pertenecer al mismo cliente que la entrada.');
                    }

                    if (! ($itemData?->active ?? false)) {
                        $validator->errors()->add("lines.$index.item_id", 'El articulo seleccionado no esta activo para nuevas entradas.');
                    }
                } else {
                    if ($sku === '') {
                        $validator->errors()->add("lines.$index.sku", 'Indica un SKU o selecciona un articulo existente.');
                    }

                    if ($description === '') {
                        $validator->errors()->add("lines.$index.description", 'Indica la descripcion para crear el articulo nuevo desde la entrada.');
                    }

                    if ($unitsPerPallet === null || $unitsPerPallet <= 0) {
                        $validator->errors()->add("lines.$index.units_per_pallet", 'Indica las unidades por pallet para crear el articulo nuevo desde la entrada.');
                    }
                }

                if ($unitsPerPallet === null && ($palletCount > 0 || ($picoUnits ?? 0) > 0)) {
                    $validator->errors()->add("lines.$index.units_per_pallet", 'Para informar pallets completos o pico, indica tambien las unidades por pallet.');
                }

                if ($unitsPerPallet !== null && ($palletCount > 0 || $picoUnits !== null)) {
                    $computedTotal = ($palletCount * $unitsPerPallet) + (int) ($picoUnits ?? 0);

                    if ($quantityUnits > 0 && $computedTotal !== $quantityUnits) {
                        $validator->errors()->add("lines.$index.quantity_units", 'La cantidad total debe coincidir con pallets completos y pico. Ajusta cantidad o paletizado.');
                    }
                }
            }
        });
    }

    private function normalizeInteger(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /** @return array<string, mixed> */
    private function normalizePeaks(array $line): array
    {
        $hasExplicitPeaks = collect(range(1, 10))->contains(function (int $number) use ($line): bool {
            $key = 'peak_'.$number;

            return array_key_exists($key, $line) && $line[$key] !== '' && $line[$key] !== null;
        });

        $peaks = [];

        foreach (range(1, 10) as $number) {
            $key = 'peak_'.$number;
            $value = $line[$key] ?? null;

            if (! $hasExplicitPeaks && $number === 1) {
                $value = $line['pico_units'] ?? null;
            }

            if ($value === '' || $value === null) {
                $peaks[$key] = null;

                continue;
            }

            $validated = filter_var($value, FILTER_VALIDATE_INT);
            $peaks[$key] = $validated === false ? $value : (int) $validated;
        }

        return $peaks;
    }

    /** @param array<string, mixed> $peaks */
    private function validPeakTotal(array $peaks): int
    {
        return collect($peaks)->sum(function (mixed $value): int {
            $validated = filter_var($value, FILTER_VALIDATE_INT);

            return $validated === false ? 0 : (int) $validated;
        });
    }

    private function normalizeNullableUpper(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_strtoupper($normalized);
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    public function expectsAiCreationFlow(): bool
    {
        return $this->input('action') === self::ACTION_CREATE_AND_EXTRACT_AI;
    }

    private function normalizeAction(mixed $value): string
    {
        $normalized = trim((string) $value);

        return in_array($normalized, [self::ACTION_CREATE_DRAFT, self::ACTION_CREATE_AND_EXTRACT_AI], true)
            ? $normalized
            : self::ACTION_CREATE_DRAFT;
    }
}
