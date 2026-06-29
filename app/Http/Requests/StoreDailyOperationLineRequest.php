<?php

namespace App\Http\Requests;

use App\Models\DailyOperationLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDailyOperationLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operation_date' => ['required', 'date'],
            'section' => ['required', Rule::in(DailyOperationLine::sections())],
            'counterparty_name' => ['required', 'string', 'max:255'],
            'pallets' => ['required', 'integer', 'min:0'],
            'observations' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
