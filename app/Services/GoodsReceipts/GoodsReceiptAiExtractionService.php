<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\Supplier;
use RuntimeException;

class GoodsReceiptAiExtractionService
{
    public function __construct(
        private readonly GoodsReceiptAiExtractorInterface $extractor,
    ) {}

    public function extractFromDocument(GoodsReceipt $receipt): GoodsReceiptAiExtractionResult
    {
        if (! config('services.openai.receipt_enabled', false)) {
            throw new RuntimeException('Interpretacion IA pendiente de activar en configuracion.');
        }

        $result = $this->extractor->extractFromDocument($receipt);
        $payload = $result->toArray();
        $warnings = $payload['warnings'] ?? [];

        if (($payload['supplier_name'] ?? null) !== null) {
            $matchedSupplierId = Supplier::query()
                ->where('active', true)
                ->where(function ($query) use ($receipt): void {
                    $query
                        ->whereNull('client_id')
                        ->orWhere('client_id', $receipt->client_id);
                })
                ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $payload['supplier_name'])])
                ->value('id');

            $payload['matched_supplier_id'] = $matchedSupplierId !== null ? (int) $matchedSupplierId : null;

            if ($matchedSupplierId === null) {
                $warnings[] = 'El proveedor detectado no coincide automaticamente con un proveedor activo del cliente. Revisa la cabecera antes de aplicar.';
            }
        }

        if (($payload['confidence'] ?? null) !== null && (float) $payload['confidence'] < 0.6) {
            $warnings[] = 'La confianza general de la interpretacion es baja. Revisa manualmente todas las lineas antes de aplicar.';
        }

        if (($payload['lines'] ?? []) === []) {
            $warnings[] = 'La IA no pudo detectar lineas utiles en el documento.';
        }

        $payload['warnings'] = array_values(array_unique(array_filter($warnings, fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')));

        return GoodsReceiptAiExtractionResult::fromArray($payload);
    }
}
