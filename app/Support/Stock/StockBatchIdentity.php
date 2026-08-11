<?php

namespace App\Support\Stock;

use App\Models\StockPallet;

final readonly class StockBatchIdentity
{
    public function __construct(
        public int $clientId,
        public int $itemId,
        mixed $lot,
        public ?int $locationId,
        ?string $locationText,
        public int $unitsPerPallet,
        ?string $status,
        ?string $stockCategory,
        ?string $blockedReason,
    ) {
        $this->lot = LotNormalizer::normalize($lot);
        $this->lotKey = mb_strtoupper($this->lot);
        $this->locationTextKey = $locationId === null
            ? mb_strtoupper(trim((string) $locationText))
            : '';
        $this->status = in_array($status, StockPallet::statuses(), true)
            ? $status
            : StockPallet::STATUS_AVAILABLE;
        $this->stockCategory = in_array($stockCategory, StockPallet::stockCategories(), true)
            ? $stockCategory
            : StockPallet::CATEGORY_IN_USE;
        $this->blockedReasonKey = mb_strtoupper(trim((string) $blockedReason));
    }

    public string $lot;

    public string $lotKey;

    public string $locationTextKey;

    public string $status;

    public string $stockCategory;

    public string $blockedReasonKey;

    public static function fromStockPallet(StockPallet $stockPallet): self
    {
        return new self(
            clientId: (int) $stockPallet->client_id,
            itemId: (int) $stockPallet->item_id,
            lot: $stockPallet->lot,
            locationId: $stockPallet->location_id !== null ? (int) $stockPallet->location_id : null,
            locationText: $stockPallet->location_text,
            unitsPerPallet: (int) $stockPallet->units_per_pallet,
            status: $stockPallet->status,
            stockCategory: $stockPallet->stock_category,
            blockedReason: $stockPallet->blocked_reason,
        );
    }

    public function hash(): string
    {
        return hash('sha256', json_encode([
            'v' => 1,
            'client_id' => $this->clientId,
            'item_id' => $this->itemId,
            'lot' => $this->lotKey,
            'location_id' => $this->locationId,
            'location_text' => $this->locationTextKey,
            'units_per_pallet' => $this->unitsPerPallet,
            'status' => $this->status,
            'stock_category' => $this->stockCategory,
            'blocked_reason' => $this->blockedReasonKey,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
