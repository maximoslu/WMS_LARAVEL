<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use LogicException;

class GoodsReceiptAiExtractionService
{
    /**
     * Placeholder del futuro servicio OCR/IA para albaranes.
     *
     * @return array<string, mixed>
     */
    public function extract(GoodsReceipt $receipt): array
    {
        throw new LogicException('La extraccion OCR/IA de entradas se implementara en una fase posterior.');
    }
}
