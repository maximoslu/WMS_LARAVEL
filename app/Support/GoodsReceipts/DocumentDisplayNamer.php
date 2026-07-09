<?php

namespace App\Support\GoodsReceipts;

use App\Models\GoodsDispatch;
use App\Models\GoodsReceipt;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DocumentDisplayNamer
{
    /**
     * Human-readable, stable name for a single receipt's document, e.g.
     * "Entrada_Saica_17" or "Entrada_SinProveedor_07". Does not disambiguate
     * against siblings; use assignNames() for that when rendering a list.
     */
    public static function baseName(GoodsReceipt $receipt): string
    {
        $supplier = self::normalizeSupplierName($receipt->supplier?->name);
        $day = $receipt->received_at?->format('d') ?? '00';

        return "Entrada_{$supplier}_{$day}";
    }

    /**
     * Assigns a display name per receipt, appending "_Entrada{id}" only to
     * receipts whose base name collides with another receipt in the same
     * list (e.g. two receipts from the same supplier on the same day).
     *
     * @param  Collection<int, GoodsReceipt>  $receipts
     * @return array<int, string> keyed by receipt id
     */
    public static function assignNames(Collection $receipts): array
    {
        $baseNames = $receipts->mapWithKeys(fn (GoodsReceipt $receipt): array => [
            $receipt->id => self::baseName($receipt),
        ]);

        $counts = $baseNames->countBy(fn (string $name): string => $name);

        return $baseNames
            ->map(fn (string $base, int $id): string => $counts[$base] > 1 ? "{$base}_Entrada{$id}" : $base)
            ->all();
    }

    public static function dispatchBaseName(GoodsDispatch $dispatch): string
    {
        $client = self::normalizeDocumentToken($dispatch->client?->code ?: $dispatch->client?->name, 'SinCliente');
        $date = $dispatch->completed_at ?? $dispatch->sent_at ?? $dispatch->created_at;
        $day = $date?->format('d') ?? '00';

        return "Salida_{$client}_{$day}";
    }

    /**
     * @param  Collection<int, GoodsDispatch>  $dispatches
     * @return array<int, string> keyed by dispatch id
     */
    public static function assignDispatchNames(Collection $dispatches): array
    {
        $baseNames = $dispatches->mapWithKeys(fn (GoodsDispatch $dispatch): array => [
            $dispatch->id => self::dispatchBaseName($dispatch),
        ]);

        $counts = $baseNames->countBy(fn (string $name): string => $name);

        return $baseNames
            ->map(fn (string $base, int $id): string => $counts[$base] > 1 ? "{$base}_Salida{$id}" : $base)
            ->all();
    }

    private static function normalizeSupplierName(?string $name): string
    {
        return self::normalizeDocumentToken($name, 'SinProveedor');
    }

    private static function normalizeDocumentToken(?string $name, string $fallback): string
    {
        if ($name === null || trim($name) === '') {
            return $fallback;
        }

        $ascii = Str::ascii($name);
        $cleaned = trim((string) preg_replace('/[^A-Za-z0-9\s]/', ' ', $ascii));
        $cleaned = trim((string) preg_replace('/\s+/', ' ', $cleaned));

        if ($cleaned === '') {
            return $fallback;
        }

        return Str::studly(Str::lower($cleaned));
    }
}
