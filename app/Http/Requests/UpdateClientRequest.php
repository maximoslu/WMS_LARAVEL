<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ADMINISTRACION) ?? false;
    }

    public function rules(): array
    {
        /** @var Client $client */
        $client = $this->route('client');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:60', Rule::unique('clients', 'code')->ignore($client)],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'delivery_postal_code' => ['nullable', 'string', 'max:20'],
            'delivery_city' => ['nullable', 'string', 'max:120'],
            'delivery_province' => ['nullable', 'string', 'max:120'],
            'delivery_country' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
