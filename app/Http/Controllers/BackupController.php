<?php

namespace App\Http\Controllers;

use App\Http\Requests\Backups\StoreBackupRequest;
use App\Models\BackupExport;
use App\Models\Client;
use App\Models\Role;
use App\Services\Backups\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->canAccessRole(Role::SUPERADMIN), 403);

        return view('backups.index', [
            'backupTypes' => BackupExport::manualTypeLabels(),
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'backups' => BackupExport::query()
                ->with(['client', 'creator.role'])
                ->latest()
                ->limit(50)
                ->get(),
            'snapshotSummary' => $this->snapshotSummary(),
            'disk' => (string) config('wms.backups.disk', 'local'),
            'path' => (string) config('wms.backups.path', 'backups'),
            'retentionDays' => (int) config('wms.backups.stock_snapshot_retention_days', 365),
        ]);
    }

    public function store(StoreBackupRequest $request, BackupService $backups): RedirectResponse
    {
        $validated = $request->validated();
        $client = isset($validated['client_id'])
            ? Client::query()->find((int) $validated['client_id'])
            : null;

        $backup = $backups->createManual($validated['type'], $client, $request->user());

        if ($backup->isCompleted()) {
            return redirect()
                ->route('backups.index')
                ->with('status', 'Backup generado correctamente.');
        }

        return redirect()
            ->route('backups.index')
            ->with('warning', 'No se ha podido completar el backup: '.$backup->error_message);
    }

    public function download(Request $request, BackupExport $backup, BackupService $backups): StreamedResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::SUPERADMIN), 403);
        abort_unless($backup->isCompleted(), 404);
        abort_unless($backups->isSafeBackupPath((string) $backup->path), 404);
        abort_unless(Storage::disk($backup->disk)->exists((string) $backup->path), 404);

        $backups->recordDownload($backup, $request->user());

        return Storage::disk($backup->disk)->download(
            (string) $backup->path,
            $backup->filename ?: basename((string) $backup->path),
            ['Content-Type' => $backup->mime_type ?: 'application/octet-stream']
        );
    }

    public function destroy(Request $request, BackupExport $backup, BackupService $backups): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::SUPERADMIN), 403);

        $backups->delete($backup, $request->user());

        return redirect()
            ->route('backups.index')
            ->with('status', 'Backup eliminado correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotSummary(): array
    {
        $latest = BackupExport::query()
            ->where('type', BackupExport::TYPE_STOCK_SNAPSHOT_DAILY)
            ->where('status', BackupExport::STATUS_COMPLETED)
            ->latest('finished_at')
            ->first();

        return [
            'active' => true,
            'clients' => Client::query()->where('active', true)->count(),
            'latest' => $latest?->finished_at,
        ];
    }
}
