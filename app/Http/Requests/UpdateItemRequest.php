<?php

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => $this->normalizeUpper($this->input('sku')),
            'description' => $this->normalizeText($this->input('description')),
            'status' => (string) $this->input('status', Item::STATUS_ACTIVE),
            'default_location_id' => $this->normalizeNullableInteger($this->input('default_location_id')),
        ]);
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'sku' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'units_per_pallet' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(Item::statuses())],
            'default_location_id' => ['nullable', 'exists:locations,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $item = $this->route('item');

            $exists = Item::query()
                ->where('client_id', $this->integer('client_id'))
                ->where('sku', (string) $this->string('sku'))
                ->when($item instanceof Item, fn ($query) => $query->whereKeyNot($item->getKey()))
                ->exists();

            if ($exists) {
                $validator->errors()->add('sku', 'Ya existe un artículo con el mismo SKU para este cliente.');
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

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeText(mixed $value): string
    {
        return trim((string) $value);
    }
}
