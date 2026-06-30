<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateManagedUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => trim((string) $this->input('email')),
        ]);
    }

    public function rules(): array
    {
        $roleRules = $this->user()?->isSuperAdmin()
            ? ['required', 'exists:roles,id']
            : ['nullable', 'exists:roles,id'];
        $selectedRole = $this->selectedRole();
        $requiresClient = $selectedRole?->slug === Role::CLIENTE;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'role_id' => $roleRules,
            'client_id' => $requiresClient
                ? ['required', 'exists:clients,id']
                : ['nullable', 'exists:clients,id'],
            'active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ];
    }

    protected function passedValidation(): void
    {
        if ($this->selectedRole()?->slug !== Role::CLIENTE) {
            $this->merge([
                'client_id' => null,
            ]);
        }
    }

    private function selectedRole(): ?Role
    {
        $roleId = $this->integer('role_id');

        if ($roleId <= 0) {
            return null;
        }

        return Role::query()->find($roleId);
    }
}
