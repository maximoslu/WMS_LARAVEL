<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ADMINISTRACION) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:60', Rule::unique('clients', 'code')],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'delivery_postal_code' => ['nullable', 'string', 'max:20'],
            'delivery_city' => ['nullable', 'string', 'max:120'],
            'delivery_province' => ['nullable', 'string', 'max:120'],
            'delivery_country' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', 'boolean'],
            'show_storage_occupancy_to_client' => ['nullable', 'boolean'],
        ];
    }
}
