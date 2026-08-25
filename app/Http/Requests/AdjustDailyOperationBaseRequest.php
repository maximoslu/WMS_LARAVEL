<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustDailyOperationBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'opening_pallets' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
