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
            $matchedSupplierId = $this->matchSupplierId($receipt, (string) $payload['supplier_name']);

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

    private function matchSupplierId(GoodsReceipt $receipt, string $detectedSupplierName): ?int
    {
        $normalizedDetected = $this->normalizeSupplierName($detectedSupplierName);

        if ($normalizedDetected === '') {
            return null;
        }

        $suppliers = Supplier::query()
            ->where('active', true)
            ->where(function ($query) use ($receipt): void {
                $query
                    ->whereNull('client_id')
                    ->orWhere('client_id', $receipt->client_id);
            })
            ->get(['id', 'name']);

        foreach ($suppliers as $supplier) {
            if ($this->normalizeSupplierName((string) $supplier->name) === $normalizedDetected) {
                return (int) $supplier->id;
            }
        }

        foreach ($suppliers as $supplier) {
            $normalizedSupplier = $this->normalizeSupplierName((string) $supplier->name);

            if ($normalizedSupplier === '') {
                continue;
            }

            if (str_contains($normalizedDetected, $normalizedSupplier) || str_contains($normalizedSupplier, $normalizedDetected)) {
                return (int) $supplier->id;
            }
        }

        return null;
    }

    private function normalizeSupplierName(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/iu', ' ', $normalized);

        return trim((string) $normalized);
    }
}
