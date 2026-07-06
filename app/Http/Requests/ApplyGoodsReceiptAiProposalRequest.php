<?php

namespace App\Http\Requests;

use App\Models\GoodsReceipt;

class ApplyGoodsReceiptAiProposalRequest extends StoreGoodsReceiptRequest
{
    protected function prepareForValidation(): void
    {
        $receipt = $this->route('goodsReceipt');

        if ($receipt instanceof GoodsReceipt) {
            $this->merge([
                'client_id' => $receipt->client_id,
            ]);
        }

        parent::prepareForValidation();
    }

    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['document']);

        return $rules;
    }
}
