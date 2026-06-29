<?php

namespace App\Http\Requests;

class UpdateDailyOperationLineRequest extends StoreDailyOperationLineRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'operation_date' => ['sometimes', 'date'],
        ];
    }
}
