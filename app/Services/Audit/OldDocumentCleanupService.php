<?php

namespace App\Services\Audit;

use App\Models\GoodsReceipt;
use App\Services\GoodsReceipts\GoodsReceiptDocumentStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OldDocumentCleanupService
{
    private const RETENTION_MONTHS = 12;

    public function __construct(
        private readonly GoodsReceiptDocumentStorage $documentStorage,
    ) {}

    public function cutoffDate(): Carbon
    {
        return now()->subMonths(self::RETENTION_MONTHS)->startOfDay();
    }

    /**
     * @return array{count: int, estimated_size_bytes: int|null, cutoff_date: string}
     */
    public function candidates(): array
    {
        $cutoff = $this->cutoffDate();
        $receipts = $this->candidateQuery($cutoff)->get(['id', 'document_path']);

        $sizeBytes = 0;
        $sizeKnown = true;

        foreach ($receipts as $receipt) {
            $disk = $this->documentStorage->resolveDisk($receipt->document_path);

            if ($disk === null) {
                $sizeKnown = false;

                continue;
            }

            $sizeBytes += Storage::disk($disk)->size($receipt->document_path);
        }

        return [
            'count' => $receipts->count(),
            'estimated_size_bytes' => $sizeKnown ? $sizeBytes : null,
            'cutoff_date' => $cutoff->toDateString(),
        ];
    }

    /**
     * Deletes the physical files for candidate documents and nulls their
     * path/mime so they stop being downloadable, while keeping the receipt,
     * its lines, stock impact and document_original_name intact for
     * traceability. Must not be called automatically; only on explicit
     * superadmin action.
     *
     * @return array{deleted: int, missing: int}
     */
    public function cleanup(int $actorId): array
    {
        $cutoff = $this->cutoffDate();
        $deleted = 0;
        $missing = 0;

        DB::transaction(function () use ($cutoff, &$deleted, &$missing): void {
            $receipts = $this->candidateQuery($cutoff)->lockForUpdate()->get();

            foreach ($receipts as $receipt) {
                $disk = $this->documentStorage->resolveDisk($receipt->document_path);

                if ($disk === null) {
                    $missing++;
                } else {
                    Storage::disk($disk)->delete($receipt->document_path);
                    $deleted++;
                }

                // document_original_name is kept on purpose: it is the minimal
                // trace that a document existed for this receipt, even after
                // the physical file is gone.
                $receipt->forceFill([
                    'document_path' => null,
                    'document_mime' => null,
                ])->save();
            }
        });

        Log::warning('goods_receipt_documents_cleanup', [
            'actor_id' => $actorId,
            'cutoff_date' => $cutoff->toDateString(),
            'deleted' => $deleted,
            'missing' => $missing,
        ]);

        return ['deleted' => $deleted, 'missing' => $missing];
    }

    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'No disponible';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;

        foreach ($units as $unit) {
            if ($value < 1024 || $unit === end($units)) {
                return number_format($value, 1, ',', '.').' '.$unit;
            }

            $value /= 1024;
        }

        return number_format($value, 1, ',', '.').' GB';
    }

    private function candidateQuery(Carbon $cutoff): Builder
    {
        return GoodsReceipt::query()
            ->whereNotNull('document_path')
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where(function (Builder $query) use ($cutoff): void {
                        $query->whereNotNull('received_at')->where('received_at', '<', $cutoff);
                    })
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->whereNull('received_at')->where('created_at', '<', $cutoff);
                    });
            });
    }
}
