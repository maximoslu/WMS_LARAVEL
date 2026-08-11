<?php

namespace App\Services\Stock;

use App\Models\Location;
use App\Models\StockPallet;
use App\Support\Stock\StockBatchIdentity;
use Illuminate\Validation\ValidationException;

class StockBatchRelocationService
{
    public function __construct(
        private readonly StockBatchIdentityService $batchIdentities,
    ) {}

    /**
     * Locks both the current and destination identities in deterministic order,
     * then locks the stock row. The caller must already be in a transaction.
     */
    public function lockForRelocation(int $stockPalletId, ?Location $destination, string $destinationErrorKey = 'destination_location_id'): StockPallet
    {
        $candidate = StockPallet::query()->findOrFail($stockPalletId);
        $sourceIdentity = StockBatchIdentity::fromStockPallet($candidate);
        $destinationIdentity = $this->destinationIdentity($candidate, $destination);

        $this->batchIdentities->lockIdentities([$sourceIdentity, $destinationIdentity]);

        $stockPallet = StockPallet::query()
            ->with(['client', 'item', 'location.warehouse'])
            ->lockForUpdate()
            ->findOrFail($stockPalletId);

        if (StockBatchIdentity::fromStockPallet($stockPallet)->hash() !== $sourceIdentity->hash()) {
            throw ValidationException::withMessages([
                'stock_pallet_id' => 'La identidad de la partida ha cambiado durante la reubicacion. Vuelve a intentarlo.',
            ]);
        }

        $destinationIdentity = $this->destinationIdentity($stockPallet, $destination);
        $collisions = $this->batchIdentities->getAfterLock($destinationIdentity)
            ->reject(fn (StockPallet $candidate): bool => (int) $candidate->id === (int) $stockPallet->id);

        if ($collisions->isNotEmpty()) {
            throw ValidationException::withMessages([
                $destinationErrorKey => 'Ya existe una partida activa con la misma identidad en la ubicacion destino.',
            ]);
        }

        return $stockPallet;
    }

    private function destinationIdentity(StockPallet $stockPallet, ?Location $destination): StockBatchIdentity
    {
        return new StockBatchIdentity(
            clientId: (int) $stockPallet->client_id,
            itemId: (int) $stockPallet->item_id,
            lot: $stockPallet->lot,
            locationId: $destination?->id,
            locationText: $destination?->code,
            unitsPerPallet: (int) $stockPallet->units_per_pallet,
            status: $stockPallet->status,
            stockCategory: $stockPallet->stock_category,
            blockedReason: $stockPallet->blocked_reason,
        );
    }
}
