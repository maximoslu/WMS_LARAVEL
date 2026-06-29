<?php

namespace App\Http\Requests;

class ExecuteAuditCleanupRequest extends PreviewAuditCleanupRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'confirmation_text' => ['required', 'string', 'in:CONFIRMAR LIMPIEZA'],
        ];
    }
}
