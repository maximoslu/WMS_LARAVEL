<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertDailyOperationDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operation_date' => ['required', 'date'],
            'opening_pallets' => ['nullable', 'integer', 'min:0'],
            'stored_pallets_today' => ['nullable', 'integer', 'min:0'],
            'moved_pallets_today' => ['nullable', 'integer', 'min:0'],
            'expected_pallets_tomorrow' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
