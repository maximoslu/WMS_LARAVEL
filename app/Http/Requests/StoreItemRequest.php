<?php

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lot = $this->normalizeNullableUpper($this->input('lot'));

        $this->merge([
            'sku' => $this->normalizeUpper($this->input('sku')),
            'description' => $this->normalizeText($this->input('description')),
            'lot' => $lot,
            'lot_key' => $lot ?? '',
        ]);
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'sku' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'lot' => ['nullable', 'string', 'max:100'],
            'lot_key' => ['nullable', 'string', 'max:100'],
            'units_per_pallet' => ['required', 'integer', 'min:1'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $exists = Item::query()
                ->where('client_id', $this->integer('client_id'))
                ->where('sku', (string) $this->string('sku'))
                ->where('lot_key', (string) ($this->input('lot_key') ?? ''))
                ->exists();

            if ($exists) {
                $validator->errors()->add('sku', 'Ya existe un articulo con el mismo SKU y lote para este cliente.');
            }
        });
    }

    private function normalizeUpper(mixed $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    private function normalizeNullableUpper(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_strtoupper($normalized);
    }

    private function normalizeText(mixed $value): string
    {
        return trim((string) $value);
    }
}
