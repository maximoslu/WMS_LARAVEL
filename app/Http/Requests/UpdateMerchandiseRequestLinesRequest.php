<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMerchandiseRequestLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'lines' => collect($this->input('lines', []))
                ->map(function ($payload): array {
                    if (! is_array($payload)) {
                        return [];
                    }

                    return [
                        'line_id' => $payload['line_id'] ?? null,
                        'quantity' => $payload['quantity'] ?? null,
                        'remove' => filter_var($payload['remove'] ?? false, FILTER_VALIDATE_BOOL),
                    ];
                })
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
            'lines.*.remove' => ['boolean'],
        ];
    }

}
