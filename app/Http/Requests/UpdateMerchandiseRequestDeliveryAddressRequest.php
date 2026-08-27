<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMerchandiseRequestDeliveryAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'delivery_address_override' => $this->boolean('delivery_address_override'),
            'delivery_address_text' => trim((string) $this->input('delivery_address_text')) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'delivery_address_override' => ['boolean'],
            'delivery_address_text' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('delivery_address_override') && blank($this->input('delivery_address_text'))) {
                $validator->errors()->add('delivery_address_text', 'Indica una dirección de entrega alternativa o desmarca la opción.');
            }
        });
    }
}
