<?php

namespace App\Models;

use App\Models\Concerns\ImmutableLedgerRecord;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    use ImmutableLedgerRecord;

    public const UPDATED_AT = null;

    public const OPENING_BALANCE = 'opening_balance';

    public const RECEIPT = 'receipt';

    public const DISPATCH = 'dispatch';

    public const MANUAL_ADJUSTMENT = 'manual_adjustment';

    public const IMPORT = 'import';

    public const IMPORT_RETIREMENT = 'import_retirement';

    public const TRANSFER = 'transfer';

    public const LOCATION_CONSOLIDATION = 'location_consolidation';

    public const WAREHOUSE_CONSOLIDATION = 'warehouse_consolidation';

    public const BLOCK = 'block';

    public const UNBLOCK = 'unblock';

    public const CANCEL = 'cancel';

    public const REVERSAL = 'reversal';

    public const CORRECTION = 'correction';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'peaks_before' => 'array',
            'peaks_delta' => 'array',
            'peaks_after' => 'array',
            'metadata' => 'array',
            'warehouse_pallets_before' => 'decimal:2',
            'warehouse_pallets_delta' => 'decimal:2',
            'warehouse_pallets_after' => 'decimal:2',
            'effective_at' => 'datetime',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public static function types(): array
    {
        return [
            self::OPENING_BALANCE,
            self::RECEIPT,
            self::DISPATCH,
            self::MANUAL_ADJUSTMENT,
            self::IMPORT,
            self::IMPORT_RETIREMENT,
            self::TRANSFER,
            self::LOCATION_CONSOLIDATION,
            self::WAREHOUSE_CONSOLIDATION,
            self::BLOCK,
            self::UNBLOCK,
            self::CANCEL,
            self::REVERSAL,
            self::CORRECTION,
        ];
    }
}
