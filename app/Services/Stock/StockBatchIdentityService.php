<?php

namespace App\Services\Stock;

use App\Models\StockPallet;
use App\Support\Stock\StockBatchIdentity;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

class StockBatchIdentityService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The unique coordination row is the database-native mutex for an identity.
     * Unlike locking stock_pallets, it also exists for an identity whose stock row
     * has not been created yet. The row lock is transaction-scoped.
     *
     * @param  iterable<StockBatchIdentity>  $identities
     */
    public function lockIdentities(iterable $identities): void
    {
        $connection = $this->db->connection();

        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Stock batch identities must be locked inside a database transaction.');
        }

        $hashes = collect($identities)
            ->map(fn (StockBatchIdentity $identity): string => $identity->hash())
            ->unique()
            ->sort()
            ->values();

        foreach ($hashes as $hash) {
            $connection->table('stock_batch_identity_locks')->insertOrIgnore([
                'identity_hash' => $hash,
                'created_at' => now(),
            ]);

            $connection->table('stock_batch_identity_locks')
                ->where('identity_hash', $hash)
                ->lockForUpdate()
                ->first();
        }
    }

    /** @return Collection<int, StockPallet> */
    public function lockAndGet(StockBatchIdentity $identity): Collection
    {
        $this->lockIdentities([$identity]);

        return $this->getAfterLock($identity);
    }

    /** @return Collection<int, StockPallet> */
    public function getAfterLock(StockBatchIdentity $identity): Collection
    {
        if ($this->db->connection()->transactionLevel() < 1) {
            throw new LogicException('Stock batches must be read after locking their identity inside a database transaction.');
        }

        return $this->query($identity)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }

    public function query(StockBatchIdentity $identity): Builder
    {
        return StockPallet::query()
            ->where('client_id', $identity->clientId)
            ->where('item_id', $identity->itemId)
            ->whereRaw('UPPER(lot) = ?', [$identity->lotKey])
            ->where('units_per_pallet', $identity->unitsPerPallet)
            ->where('active', true)
            ->where(function (Builder $query) use ($identity): void {
                $query->where('status', $identity->status);

                if ($identity->status === StockPallet::STATUS_AVAILABLE) {
                    $query->orWhereNull('status');
                }
            })
            ->where(function (Builder $query) use ($identity): void {
                $query->where('stock_category', $identity->stockCategory);

                if ($identity->stockCategory === StockPallet::CATEGORY_IN_USE) {
                    $query->orWhereNull('stock_category');
                }
            })
            ->when(
                $identity->locationId !== null,
                fn (Builder $query): Builder => $query->where('location_id', $identity->locationId),
                fn (Builder $query): Builder => $query
                    ->whereNull('location_id')
                    ->whereRaw("UPPER(TRIM(COALESCE(location_text, ''))) = ?", [$identity->locationTextKey]),
            )
            ->whereRaw("UPPER(TRIM(COALESCE(blocked_reason, ''))) = ?", [$identity->blockedReasonKey]);
    }
}
