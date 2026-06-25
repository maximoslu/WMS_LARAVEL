<?php

namespace App\Http\Requests;

use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->normalizeUpper($this->input('code')),
            'name' => $this->normalizeText($this->input('name')),
        ]);
    }

    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'exists:clients,id'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->duplicateExists()) {
                $validator->errors()->add('code', 'Ya existe un almacen con el mismo codigo en este ambito.');
            }
        });
    }

    private function duplicateExists(): bool
    {
        $query = Warehouse::query()
            ->where('code', (string) $this->string('code'));

        if ($this->filled('client_id')) {
            $query->where('client_id', $this->integer('client_id'));
        } else {
            $query->whereNull('client_id');
        }

        return $query->exists();
    }

    private function normalizeUpper(mixed $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    private function normalizeText(mixed $value): string
    {
        return trim((string) $value);
    }
}
