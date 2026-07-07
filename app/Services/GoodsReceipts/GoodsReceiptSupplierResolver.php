<?php

namespace App\Services\GoodsReceipts;

use App\Models\Supplier;
use Illuminate\Validation\ValidationException;

class GoodsReceiptSupplierResolver
{
    /**
     * @return array{supplier: Supplier, created: bool}
     */
    public function createOrReuseForQuickAdd(int $clientId, string $name): array
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            throw ValidationException::withMessages([
                'name' => 'El nombre del proveedor es obligatorio.',
            ]);
        }

        $existingSupplier = Supplier::query()
            ->where(function ($query) use ($clientId): void {
                $query
                    ->whereNull('client_id')
                    ->orWhere('client_id', $clientId);
            })
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($normalizedName)])
            ->first();

        if ($existingSupplier instanceof Supplier) {
            return ['supplier' => $existingSupplier, 'created' => false];
        }

        $supplier = Supplier::query()->create([
            'client_id' => $clientId,
            'name' => $normalizedName,
            'active' => true,
        ]);

        return ['supplier' => $supplier, 'created' => true];
    }
}
