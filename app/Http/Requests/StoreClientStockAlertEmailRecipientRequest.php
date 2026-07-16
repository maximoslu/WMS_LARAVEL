<?php

namespace App\Http\Requests;

use App\Models\ClientStockAlertEmailRecipient;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientStockAlertEmailRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ADMINISTRACION) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'active' => $this->boolean('active', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique(ClientStockAlertEmailRecipient::class, 'email')
                    ->where('client_id', $this->route('client')?->id),
            ],
            'active' => ['boolean'],
        ];
    }
}
