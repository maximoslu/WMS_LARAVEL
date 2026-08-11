<?php

namespace Tests\Unit;

use App\Models\StockPallet;
use App\Support\Stock\StockBatchIdentity;
use PHPUnit\Framework\TestCase;

class StockBatchIdentityTest extends TestCase
{
    public function test_no_lot_aliases_share_the_same_identity(): void
    {
        $hashes = collect([null, '', 'NO LOTE', ' sin   lote '])
            ->map(fn (mixed $lot): string => $this->identity(lot: $lot)->hash())
            ->unique();

        $this->assertCount(1, $hashes);
    }

    public function test_canonical_locations_and_legacy_text_locations_are_scoped_correctly(): void
    {
        $canonical = $this->identity(locationId: 10, locationText: 'ignored');
        $sameCanonical = $this->identity(locationId: 10, locationText: 'another label');
        $otherCanonical = $this->identity(locationId: 11, locationText: null);
        $legacyA = $this->identity(locationId: null, locationText: ' pasillo a ');
        $legacyB = $this->identity(locationId: null, locationText: 'PASILLO A');
        $unlocated = $this->identity(locationId: null, locationText: null);

        $this->assertSame($canonical->hash(), $sameCanonical->hash());
        $this->assertNotSame($canonical->hash(), $otherCanonical->hash());
        $this->assertSame($legacyA->hash(), $legacyB->hash());
        $this->assertNotSame($legacyA->hash(), $unlocated->hash());
    }

    public function test_client_item_lot_status_category_and_units_per_pallet_are_identity_fields(): void
    {
        $base = $this->identity();

        $this->assertNotSame($base->hash(), $this->identity(clientId: 2)->hash());
        $this->assertNotSame($base->hash(), $this->identity(itemId: 2)->hash());
        $this->assertNotSame($base->hash(), $this->identity(lot: 'LOT-B')->hash());
        $this->assertNotSame($base->hash(), $this->identity(unitsPerPallet: 200)->hash());
        $this->assertNotSame($base->hash(), $this->identity(status: StockPallet::STATUS_BLOCKED)->hash());
        $this->assertNotSame($base->hash(), $this->identity(stockCategory: StockPallet::CATEGORY_MISC)->hash());
    }

    public function test_real_lot_display_case_is_preserved_but_database_equivalent_case_shares_identity(): void
    {
        $mixed = $this->identity(lot: ' Lot-Mixto-01 ');
        $upper = $this->identity(lot: 'LOT-MIXTO-01');

        $this->assertSame('Lot-Mixto-01', $mixed->lot);
        $this->assertSame($mixed->hash(), $upper->hash());
    }

    private function identity(
        int $clientId = 1,
        int $itemId = 1,
        mixed $lot = 'LOT-A',
        ?int $locationId = null,
        ?string $locationText = null,
        int $unitsPerPallet = 100,
        string $status = StockPallet::STATUS_AVAILABLE,
        string $stockCategory = StockPallet::CATEGORY_IN_USE,
    ): StockBatchIdentity {
        return new StockBatchIdentity(
            clientId: $clientId,
            itemId: $itemId,
            lot: $lot,
            locationId: $locationId,
            locationText: $locationText,
            unitsPerPallet: $unitsPerPallet,
            status: $status,
            stockCategory: $stockCategory,
            blockedReason: null,
        );
    }
}
