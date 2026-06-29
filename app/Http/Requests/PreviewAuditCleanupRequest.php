<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewAuditCleanupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cleanup_type' => trim((string) $this->input('cleanup_type')),
            'client_id' => $this->normalizeNullableInteger($this->input('client_id')),
            'status' => $this->normalizeNullableText($this->input('status')),
            'date_from' => $this->normalizeNullableText($this->input('date_from')),
            'date_to' => $this->normalizeNullableText($this->input('date_to')),
        ]);
    }

    public function rules(): array
    {
        return [
            'cleanup_type' => ['required', Rule::in(['notifications', 'stock_imports', 'failed_jobs'])],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'status' => ['nullable', 'string', 'max:50'],
        ];
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
