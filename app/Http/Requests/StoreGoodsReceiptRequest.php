<?php

namespace App\Http\Requests;

use App\Models\Item;
use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))
            ->map(function (mixed $line): array {
                $line = is_array($line) ? $line : [];

                $quantityUnits = $this->normalizeInteger($line['quantity_units'] ?? 0);
                $unitsPerPallet = $this->normalizeNullableInteger($line['units_per_pallet'] ?? null);
                $palletCount = $this->normalizeInteger($line['pallet_count'] ?? 0);
                $picoUnits = $this->normalizeNullableInteger($line['pico_units'] ?? null);

                if ($unitsPerPallet !== null && ($palletCount > 0 || ($picoUnits ?? 0) > 0)) {
                    $computedTotal = ($palletCount * $unitsPerPallet) + (int) ($picoUnits ?? 0);

                    if ($quantityUnits === 0 && $computedTotal > 0) {
                        $quantityUnits = $computedTotal;
                    }
                }

                if ($unitsPerPallet !== null && $quantityUnits > 0 && $palletCount === 0 && $picoUnits === null) {
                    $palletCount = intdiv($quantityUnits, $unitsPerPallet);
                    $picoUnits = $quantityUnits % $unitsPerPallet;
                }

                return [
                    'item_id' => $this->normalizeNullableInteger($line['item_id'] ?? null),
                    'sku' => $this->normalizeNullableUpper($line['sku'] ?? null),
                    'description' => $this->normalizeNullableText($line['description'] ?? null),
                    'lot' => $this->normalizeNullableUpper($line['lot'] ?? null),
                    'quantity_units' => $quantityUnits,
                    'units_per_pallet' => $unitsPerPallet,
                    'pallet_count' => $palletCount,
                    'pico_units' => $picoUnits,
                    'location_id' => $this->normalizeNullableInteger($line['location_id'] ?? null),
                    'notes' => $this->normalizeNullableText($line['notes'] ?? null),
                ];
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
                    || $line['location_id'] !== null
                    || $line['notes'] !== null;
            })
            ->values()
            ->all();

        $this->merge([
            'receipt_number' => $this->normalizeNullableUpper($this->input('receipt_number')),
            'external_document_number' => $this->normalizeNullableUpper($this->input('external_document_number')),
            'notes' => $this->normalizeNullableText($this->input('notes')),
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'receipt_number' => ['nullable', 'string', 'max:150'],
            'external_document_number' => ['nullable', 'string', 'max:150'],
            'received_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'exists:items,id'],
            'lines.*.sku' => ['nullable', 'string', 'max:100'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.lot' => ['nullable', 'string', 'max:100'],
            'lines.*.quantity_units' => ['required', 'integer', 'min:0'],
            'lines.*.units_per_pallet' => ['nullable', 'integer', 'min:1'],
            'lines.*.pallet_count' => ['nullable', 'integer', 'min:0'],
            'lines.*.pico_units' => ['nullable', 'integer', 'min:0'],
            'lines.*.location_id' => ['nullable', 'exists:locations,id'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
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
                $quantityUnits = (int) ($line['quantity_units'] ?? 0);
                $unitsPerPallet = isset($line['units_per_pallet']) ? (int) $line['units_per_pallet'] : null;
                $palletCount = (int) ($line['pallet_count'] ?? 0);
                $picoUnits = isset($line['pico_units']) ? (int) $line['pico_units'] : null;

                if ($itemId > 0) {
                    $itemClientId = Item::query()->whereKey($itemId)->value('client_id');

                    if ((int) $itemClientId !== $clientId) {
                        $validator->errors()->add("lines.$index.item_id", 'El articulo debe pertenecer al mismo cliente que la entrada.');
                    }
                }

                if ($unitsPerPallet === null && ($palletCount > 0 || ($picoUnits ?? 0) > 0)) {
                    $validator->errors()->add("lines.$index.units_per_pallet", 'Para informar palets completos o pico, indica tambien las unidades por palet.');
                }

                if ($unitsPerPallet !== null && ($palletCount > 0 || $picoUnits !== null)) {
                    $computedTotal = ($palletCount * $unitsPerPallet) + (int) ($picoUnits ?? 0);

                    if ($quantityUnits > 0 && $computedTotal !== $quantityUnits) {
                        $validator->errors()->add("lines.$index.quantity_units", 'La cantidad total debe coincidir con palets completos y pico.');
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
}
