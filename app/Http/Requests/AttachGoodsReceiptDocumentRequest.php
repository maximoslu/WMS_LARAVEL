<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Support\GoodsReceipts\GoodsReceiptDocumentRules;
use Illuminate\Foundation\Http\FormRequest;

class AttachGoodsReceiptDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessRole(Role::ALMACEN) ?? false;
    }

    public function rules(): array
    {
        return [
            'document' => GoodsReceiptDocumentRules::rules(required: true),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return GoodsReceiptDocumentRules::messages();
    }
}
