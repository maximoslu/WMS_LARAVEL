<?php

namespace App\Services\Backups;

use App\Models\BackupExport;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StockSnapshotService
{
    public function __construct(private readonly BackupService $backups)
    {
    }

    /**
     * @return array{planned:int,created:int,skipped:int,deleted:int,items:array<int, array<string, mixed>>}
     */
    public function createDailySnapshots(?Client $onlyClient = null, ?string $date = null, bool $force = false, bool $dryRun = false): array
    {
        $date ??= now()->toDateString();
        $clients = $this->clients($onlyClient);
        $items = [];
        $created = 0;
        $skipped = 0;

        foreach ($clients as $client) {
            $path = $this->snapshotPath($client, $date);
            $existing = $this->existingSnapshot($client, $date);
            $willCreate = $force || ! $existing instanceof BackupExport;

            $items[] = [
                'client_id' => $client->id,
                'client_code' => $client->code,
                'path' => $path,
                'action' => $willCreate ? ($force && $existing ? 'regenerate' : 'create') : 'skip',
            ];

            if ($dryRun) {
                continue;
            }

            if (! $willCreate) {
                $skipped++;
                continue;
            }

            if ($existing instanceof BackupExport && $force) {
                if ($this->backups->isSafeBackupPath((string) $existing->path) && Storage::disk($existing->disk)->exists((string) $existing->path)) {
                    Storage::disk($existing->disk)->delete((string) $existing->path);
                }

                $existing->delete();
            }

            $backup = BackupExport::query()->create([
                'type' => BackupExport::TYPE_STOCK_SNAPSHOT_DAILY,
                'scope' => $client->code,
                'client_id' => $client->id,
                'status' => BackupExport::STATUS_RUNNING,
                'disk' => (string) config('wms.backups.disk', 'local'),
                'started_at' => now(),
                'metadata' => [
                    'snapshot_date' => $date,
                    'retention_days' => (int) config('wms.backups.stock_snapshot_retention_days', 365),
                    'forced' => $force,
                ],
            ]);

            $artifact = $this->backups->createStockExport($backup, $client, $date, $path);
            $backup->forceFill([
                'status' => BackupExport::STATUS_COMPLETED,
                'path' => $artifact['path'],
                'filename' => $artifact['filename'],
                'mime_type' => $artifact['mime_type'],
                'size_bytes' => $artifact['size_bytes'],
                'checksum' => $artifact['checksum'],
                'finished_at' => now(),
                'metadata' => array_merge($backup->metadata ?? [], $artifact['metadata']),
            ])->save();

            $created++;
        }

        return [
            'planned' => $clients->count(),
            'created' => $created,
            'skipped' => $dryRun ? count(array_filter($items, fn (array $item): bool => $item['action'] === 'skip')) : $skipped,
            'deleted' => 0,
            'items' => $items,
        ];
    }

    /**
     * @return array{matched:int,deleted:int,items:array<int, array<string, mixed>>}
     */
    public function prune(int $days, string $type, bool $dryRun = true): array
    {
        $cutoff = Carbon::today()->subDays($days);
        $query = BackupExport::query()
            ->where('type', $type)
            ->where('status', BackupExport::STATUS_COMPLETED)
            ->where('created_at', '<', $cutoff);

        $items = [];
        $deleted = 0;

        foreach ($query->get() as $backup) {
            $items[] = [
                'id' => $backup->id,
                'path' => $backup->path,
                'created_at' => optional($backup->created_at)->toDateTimeString(),
            ];

            if (! $dryRun) {
                if ($this->backups->isSafeBackupPath((string) $backup->path) && Storage::disk($backup->disk)->exists((string) $backup->path)) {
                    Storage::disk($backup->disk)->delete((string) $backup->path);
                }

                $backup->delete();
                $deleted++;
            }
        }

        return [
            'matched' => count($items),
            'deleted' => $deleted,
            'items' => $items,
        ];
    }

    private function existingSnapshot(Client $client, string $date): ?BackupExport
    {
        return BackupExport::query()
            ->where('type', BackupExport::TYPE_STOCK_SNAPSHOT_DAILY)
            ->where('client_id', $client->id)
            ->where('status', BackupExport::STATUS_COMPLETED)
            ->where('path', $this->snapshotPath($client, $date))
            ->first();
    }

    private function snapshotPath(Client $client, string $date): string
    {
        $code = $this->clientCodeSegment($client);
        $yearMonth = Carbon::parse($date)->format('Y/m');

        return trim((string) config('wms.backups.path', 'backups'), '/')
            .'/stock-snapshots/'.$code.'/'.$yearMonth.'/'.$date.'_stock_'.$code.'.csv.gz';
    }

    private function clientCodeSegment(Client $client): string
    {
        $code = $client->code ?: $client->name;
        $segment = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $code);

        return trim((string) $segment, '_') !== '' ? Str::upper(trim((string) $segment, '_')) : 'CLIENTE_'.$client->id;
    }

    /**
     * @return Collection<int, Client>
     */
    private function clients(?Client $onlyClient): Collection
    {
        if ($onlyClient instanceof Client) {
            return collect([$onlyClient]);
        }

        return Client::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }
}
