<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class QuickCreateGoodsReceiptItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => trim((string) $this->input('sku')),
            'description' => trim((string) $this->input('description')),
        ]);
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'sku' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'units_per_pallet' => ['required', 'integer', 'min:1'],
        ];
    }
}
