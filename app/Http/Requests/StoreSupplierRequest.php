<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->normalizeText($this->input('name')),
            'tax_id' => $this->normalizeNullableUpper($this->input('tax_id')),
            'email' => $this->normalizeNullableLower($this->input('email')),
            'phone' => $this->normalizeNullableText($this->input('phone')),
            'contact_name' => $this->normalizeNullableText($this->input('contact_name')),
            'notes' => $this->normalizeNullableText($this->input('notes')),
        ]);
    }

    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    private function normalizeText(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeNullableUpper(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_strtoupper($normalized);
    }

    private function normalizeNullableLower(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_strtolower($normalized);
    }
}
