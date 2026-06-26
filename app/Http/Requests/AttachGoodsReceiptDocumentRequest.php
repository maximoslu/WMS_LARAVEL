<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachGoodsReceiptDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }
}
