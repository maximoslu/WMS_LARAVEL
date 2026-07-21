<?php

namespace App\Http\Requests\Backups;

use App\Models\BackupExport;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::SUPERADMIN) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(BackupExport::manualTypes())],
            'client_id' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === BackupExport::TYPE_STOCK_CLIENT),
                'nullable',
                'integer',
                'exists:clients,id',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Selecciona el tipo de copia.',
            'type.in' => 'El tipo de copia seleccionado no es valido.',
            'client_id.required' => 'Selecciona el cliente para generar el stock por cliente.',
            'client_id.exists' => 'El cliente seleccionado no existe.',
        ];
    }
}
