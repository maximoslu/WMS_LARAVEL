<?php

namespace App\Http\Controllers\Traceability;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traceability\Concerns\AuthorizesTraceability;
use App\Models\Client;
use App\Models\InventoryMovement;
use App\Services\Audit\AuditLogService;
use App\Support\WmsNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TraceabilityReportController extends Controller
{
    use AuthorizesTraceability;

    public function index(Request $request): View
    {
        $this->authorizeTraceabilityAdmin($request);

        return view('traceability.reports.index', [
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function movementsCsv(Request $request, AuditLogService $audit): StreamedResponse|Response
    {
        $this->authorizeTraceabilityAdmin($request);
        $filters = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'movement_type' => ['nullable', 'string', 'max:50'],
            'lot' => ['nullable', 'string', 'max:100'],
        ]);
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        $to = Carbon::parse($filters['date_to'])->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'El periodo maximo es de 366 dias.');
        $query = InventoryMovement::query()
            ->where('client_id', $filters['client_id'])
            ->whereBetween('effective_at', [$from, $to])
            ->when(filled($filters['movement_type'] ?? null), fn (Builder $builder) => $builder->where('movement_type', $filters['movement_type']))
            ->when(filled($filters['lot'] ?? null), fn (Builder $builder) => $builder->where('lot', $filters['lot']))
            ->orderBy('effective_at')
            ->orderBy('id');
        abort_if((clone $query)->limit(10001)->count() > 10000, 422, 'La exportacion supera 10.000 filas. Acota el periodo o los filtros.');
        $audit->record(
            event: 'traceability_report_exported',
            module: 'reports',
            description: 'Exportacion CSV de movimientos de inventario.',
            user: $request->user(),
            clientId: (int) $filters['client_id'],
            metadata: ['filters' => $filters, 'format' => 'csv'],
        );
        $generatedAt = now();
        $userName = $request->user()->name;

        return response()->streamDownload(function () use ($query, $generatedAt, $userName, $filters): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Generado', $generatedAt->toDateTimeString(), 'Usuario', $userName], ';');
            fputcsv($output, ['Filtros', json_encode($filters, JSON_UNESCAPED_UNICODE)], ';');
            fputcsv($output, ['Fecha efectiva', 'Fecha registro', 'Cliente', 'SKU', 'Descripcion', 'Lote', 'Tipo', 'Unidades antes', 'Variacion', 'Unidades despues', 'Pallets', 'Usuario', 'Correlacion'], ';');
            foreach ($query->cursor() as $movement) {
                fputcsv($output, [
                    $movement->effective_at?->toDateTimeString(),
                    $movement->recorded_at?->toDateTimeString(),
                    $movement->client_name,
                    $movement->sku,
                    $movement->description,
                    $movement->lot,
                    $movement->movement_type,
                    $movement->units_before,
                    $movement->units_delta,
                    $movement->units_after,
                    $movement->warehouse_pallets_delta,
                    $movement->user_name,
                    $movement->correlation_id,
                ], ';');
            }
            fclose($output);
        }, 'movimientos-trazabilidad-'.$generatedAt->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
