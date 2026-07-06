<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;

interface GoodsReceiptAiExtractorInterface
{
    public function extractFromDocument(GoodsReceipt $receipt): GoodsReceiptAiExtractionResult;
}
