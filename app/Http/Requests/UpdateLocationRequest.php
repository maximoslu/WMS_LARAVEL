<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->normalizeUpper($this->input('code')),
            'name' => $this->normalizeNullableText($this->input('name')),
            'zone' => $this->normalizeNullableText($this->input('zone')),
            'aisle' => $this->normalizeNullableText($this->input('aisle')),
            'rack' => $this->normalizeNullableText($this->input('rack')),
            'level' => $this->normalizeNullableText($this->input('level')),
            'position' => $this->normalizeNullableText($this->input('position')),
        ]);
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'code' => ['required', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:255'],
            'zone' => ['nullable', 'string', 'max:50'],
            'aisle' => ['nullable', 'string', 'max:50'],
            'rack' => ['nullable', 'string', 'max:50'],
            'level' => ['nullable', 'string', 'max:50'],
            'position' => ['nullable', 'string', 'max:50'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $location = $this->route('location');

            $exists = Location::query()
                ->where('warehouse_id', $this->integer('warehouse_id'))
                ->where('code', (string) $this->string('code'))
                ->when($location instanceof Location, fn ($query) => $query->whereKeyNot($location->getKey()))
                ->exists();

            if ($exists) {
                $validator->errors()->add('code', 'Ya existe una ubicacion con el mismo codigo en este almacen.');
            }
        });
    }

    private function normalizeUpper(mixed $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
