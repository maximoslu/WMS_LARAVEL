<?php

namespace App\Http\Requests;

use App\Models\ClientDispatchEmailRecipient;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientDispatchEmailRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ADMINISTRACION) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'name' => trim((string) $this->input('name')) !== '' ? trim((string) $this->input('name')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique(ClientDispatchEmailRecipient::class, 'email')
                    ->where('client_id', $this->route('client')?->id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
