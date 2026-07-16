<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\StockImport;
use App\Services\Audit\AuditLogService;
use App\Services\Stock\StockExcelImportService;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class StockImportController extends Controller
{
    public function __construct(
        private readonly StockExcelImportService $stockExcelImportService,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): View
    {
        return view('stock.import', [
            'clients' => Client::query()->orderBy('name')->get(),
            'recentImports' => StockImport::query()
                ->with(['client', 'uploadedBy'])
                ->latest()
                ->limit(10)
                ->get(),
            'preview' => null,
            'stockImport' => null,
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function preview(Request $request): View
    {
        $validated = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'file' => ['required', 'file', 'mimes:xlsx'],
        ]);

        $client = Client::query()->findOrFail($validated['client_id']);
        $result = $this->stockExcelImportService->createPreview(
            $client,
            $request->user(),
            $request->file('file'),
        );

        return view('stock.import', [
            'clients' => Client::query()->orderBy('name')->get(),
            'recentImports' => StockImport::query()
                ->with(['client', 'uploadedBy'])
                ->latest()
                ->limit(10)
                ->get(),
            'preview' => $result['preview'],
            'stockImport' => $result['stock_import'],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stock_import_id' => ['required', 'integer', Rule::exists('stock_imports', 'id')],
        ]);

        $stockImport = StockImport::query()
            ->with('client')
            ->findOrFail($validated['stock_import_id']);

        abort_unless($request->user()?->isSuperAdmin(), 403);

        try {
            $result = $this->stockExcelImportService->confirm($stockImport, $request->user());
        } catch (Throwable $exception) {
            report($exception);
            $this->audit->record(
                event: 'stock_import_failed',
                module: 'stock_imports',
                description: 'La confirmacion de la importacion de stock ha fallado.',
                auditable: $stockImport,
                user: $request->user(),
                clientId: $stockImport->client_id,
                metadata: ['exception' => $exception::class],
                severity: 'warning',
            );

            return redirect()
                ->route('stock.import')
                ->withErrors([
                    'file' => $exception->getMessage(),
                ]);
        }

        return redirect()
            ->route('stock.index', ['client_id' => $stockImport->client_id])
            ->with('status', 'Importacion completada para '.$stockImport->client->name.'. Filas importadas: '.$result['imported_rows'].'.');
    }
}
