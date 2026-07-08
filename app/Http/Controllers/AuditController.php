<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExecuteAuditCleanupRequest;
use App\Http\Requests\PreviewAuditCleanupRequest;
use App\Models\Client;
use App\Models\Role;
use App\Models\StockImport;
use App\Services\Audit\AuditCleanupService;
use App\Services\Audit\OldDocumentCleanupService;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __construct(
        private readonly AuditCleanupService $cleanupService,
        private readonly OldDocumentCleanupService $documentCleanupService,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->canAccessRole(Role::ADMINISTRACION), 403);

        $isSuperAdmin = $request->user()?->isSuperAdmin() === true;

        return view('audit.index', [
            'clients' => Client::query()->orderBy('name')->get(),
            'cleanupTypes' => $this->cleanupTypes(),
            'importStatuses' => [
                StockImport::STATUS_FAILED => StockImport::statusLabelFor(StockImport::STATUS_FAILED),
                StockImport::STATUS_PENDING_CONFIRMATION => StockImport::statusLabelFor(StockImport::STATUS_PENDING_CONFIRMATION),
                StockImport::STATUS_PREVIEWED => StockImport::statusLabelFor(StockImport::STATUS_PREVIEWED),
            ],
            'previewResult' => session('audit_cleanup_preview'),
            'filters' => [
                'cleanup_type' => old('cleanup_type', 'notifications'),
                'date_from' => old('date_from'),
                'date_to' => old('date_to'),
                'client_id' => old('client_id'),
                'status' => old('status'),
            ],
            'canExecuteCleanup' => $isSuperAdmin,
            'isSuperAdmin' => $isSuperAdmin,
            'documentCleanupSummary' => $isSuperAdmin ? $this->documentCleanupService->candidates() : null,
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function executeDocumentCleanup(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $result = $this->documentCleanupService->cleanup((int) $request->user()->id);

        $status = $result['deleted'] > 0 || $result['missing'] > 0
            ? "Limpieza de archivos ejecutada. Eliminados: {$result['deleted']}."
                .($result['missing'] > 0 ? " Referencias sin archivo en disco saneadas: {$result['missing']}." : '')
            : 'No habia archivos de mas de 12 meses para limpiar.';

        return redirect()
            ->route('audit.index')
            ->with('status', $status);
    }

    public function previewCleanup(PreviewAuditCleanupRequest $request): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ADMINISTRACION), 403);

        $validated = $request->validated();
        $preview = $this->cleanupService->preview($validated);

        return redirect()
            ->route('audit.index')
            ->withInput()
            ->with('audit_cleanup_preview', [
                ...$preview,
                'filters' => $validated,
            ]);
    }

    public function executeCleanup(ExecuteAuditCleanupRequest $request): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $validated = $request->validated();
        $result = $this->cleanupService->execute($validated, (int) $request->user()->id);

        return redirect()
            ->route('audit.index')
            ->with('status', 'Limpieza ejecutada correctamente. Registros eliminados: '.$result['deleted'].'.');
    }

    /**
     * @return array<string, string>
     */
    private function cleanupTypes(): array
    {
        return [
            'notifications' => 'Notificaciones antiguas',
            'stock_imports' => 'Importaciones fallidas o previsualizadas',
            'failed_jobs' => 'Jobs fallidos',
        ];
    }
}
