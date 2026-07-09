<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDailyOperationLineRequest;
use App\Http\Requests\UpdateDailyOperationLineRequest;
use App\Http\Requests\UpsertDailyOperationDayRequest;
use App\Models\Client;
use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\Role;
use App\Services\DailyOperations\DailyOperationLineAutomationService;
use App\Services\DailyOperations\DailyOperationRecalculationService;
use App\Services\DailyOperations\DailyOperationTotalsService;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DailyOperationController extends Controller
{
    public function __construct(
        private readonly DailyOperationLineAutomationService $lineAutomationService,
        private readonly DailyOperationRecalculationService $recalculationService,
        private readonly DailyOperationTotalsService $totalsService,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);

        $selectedDate = $this->selectedDate($request);
        $clients = Client::query()->where('active', true)->orderBy('name')->get();
        $selectedClient = $this->selectedClient($request, $clients);

        $day = null;

        if ($selectedClient !== null) {
            $day = DailyOperationDay::query()
                ->with(['client', 'creator', 'updater', 'lines.creator'])
                ->whereDate('operation_date', $selectedDate->toDateString())
                ->where('client_id', $selectedClient->id)
                ->first();
        }

        $lineBeingEdited = null;
        $editLineId = $request->integer('edit_line');

        if ($day !== null && $editLineId > 0) {
            $lineBeingEdited = $day->lines->firstWhere('id', $editLineId);
        }

        $sectionBreakdown = $day !== null
            ? $this->totalsService->sectionBreakdown($day)
            : collect(DailyOperationLine::sections())->mapWithKeys(fn (string $section) => [$section => 0])->all();

        return view('daily-operations.index', [
            'day' => $day,
            'clients' => $clients,
            'selectedClient' => $selectedClient,
            'selectedDate' => $selectedDate,
            'sectionOptions' => DailyOperationLine::sectionOptions(),
            'sectionTotals' => $sectionBreakdown,
            'billingDetails' => $day !== null ? $this->billingDetails($day) : [],
            'canManage' => $request->user()?->canAccessRole(Role::ALMACEN) === true,
            'lineBeingEdited' => $lineBeingEdited,
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function upsertDay(UpsertDailyOperationDayRequest $request): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);

        $validated = $request->validated();

        $day = DailyOperationDay::query()->firstOrNew([
            'operation_date' => $validated['operation_date'],
            'client_id' => $validated['client_id'],
        ]);

        if (! $day->exists) {
            $day->created_by = $request->user()->id;
        }

        $day = $this->totalsService->syncDay(
            $day,
            isset($validated['opening_pallets']) ? (int) $validated['opening_pallets'] : null,
            $validated['notes'] ?? null,
            (int) $request->user()->id,
        );

        return redirect()
            ->route('daily-operations.index', ['date' => $validated['operation_date'], 'client_id' => $validated['client_id']])
            ->with('status', 'Resumen diario guardado correctamente.');
    }

    public function storeLine(StoreDailyOperationLineRequest $request): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);

        $validated = $request->validated();
        $day = $this->firstOrCreateDay($validated['operation_date'], (int) $validated['client_id'], (int) $request->user()->id);

        $sortOrder = (int) $day->lines()->max('sort_order') + 1;

        $line = $day->lines()->create([
            'section' => $validated['section'],
            'counterparty_name' => $validated['counterparty_name'],
            'pallets' => $validated['pallets'],
            'observations' => $validated['observations'] ?? null,
            'without_booking' => false,
            'is_auto_generated' => false,
            'source_type' => null,
            'source_id' => null,
            'parent_line_id' => null,
            'sort_order' => $sortOrder,
            'created_by' => $request->user()->id,
        ]);

        $this->lineAutomationService->syncAssociatedLinesForManualLine($line, (int) $request->user()->id);
        $this->totalsService->syncDay($day->fresh(['lines']), null, null, (int) $request->user()->id);

        return redirect()
            ->route('daily-operations.index', ['date' => $validated['operation_date'], 'client_id' => $validated['client_id']])
            ->with('status', 'Linea diaria guardada correctamente.');
    }

    public function recalculate(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);

        $validated = $request->validate([
            'operation_date' => ['required', 'date'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
        ]);

        $day = $this->recalculationService->rebuildForDateAndClient(
            $validated['operation_date'],
            (int) $validated['client_id'],
            (int) $request->user()->id,
        );

        return redirect()
            ->route('daily-operations.index', [
                'date' => $day->operation_date?->toDateString() ?? $validated['operation_date'],
                'client_id' => $validated['client_id'],
            ])
            ->with('status', 'Operaciones recalculadas desde entradas, salidas y stock activo. Las lineas manuales se han conservado.');
    }

    public function updateLine(UpdateDailyOperationLineRequest $request, DailyOperationLine $dailyOperationLine): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);
        abort_if(! $dailyOperationLine->canBeManuallyManaged(), 422, 'Las lineas automaticas de operativa real se recalculan desde la operativa y no se editan manualmente.');

        $validated = $request->validated();
        $targetDate = $validated['operation_date'] ?? $dailyOperationLine->day?->operation_date?->toDateString() ?? now()->toDateString();
        $previousDay = $dailyOperationLine->day;

        if ($dailyOperationLine->source_type !== DailyOperationLine::SOURCE_MANUAL_LINE) {
            $this->lineAutomationService->removeAssociatedAutoLinesForManualLine($dailyOperationLine);
        }

        $day = $this->firstOrCreateDay($targetDate, (int) $validated['client_id'], (int) $request->user()->id);

        $dailyOperationLine->update([
            'day_id' => $day->id,
            'section' => $validated['section'],
            'counterparty_name' => $validated['counterparty_name'],
            'pallets' => $validated['pallets'],
            'observations' => $validated['observations'] ?? null,
        ]);

        if ($dailyOperationLine->source_type !== DailyOperationLine::SOURCE_MANUAL_LINE) {
            $this->lineAutomationService->syncAssociatedLinesForManualLine($dailyOperationLine->fresh(), (int) $request->user()->id);
        }

        if ($previousDay !== null && $previousDay->id !== $day->id) {
            $this->totalsService->syncDay($previousDay->fresh(['lines']), null, null, (int) $request->user()->id);
        }

        $this->totalsService->syncDay($day->fresh(['lines']), null, null, (int) $request->user()->id);

        return redirect()
            ->route('daily-operations.index', ['date' => $targetDate, 'client_id' => $validated['client_id']])
            ->with('status', 'Linea diaria actualizada correctamente.');
    }

    public function destroyLine(Request $request, DailyOperationLine $dailyOperationLine): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);
        abort_if(! $dailyOperationLine->canBeManuallyManaged(), 422, 'Las lineas automaticas de operativa real se eliminan recalculando la operativa.');

        $day = $dailyOperationLine->day;
        $date = $dailyOperationLine->day?->operation_date?->toDateString() ?? now()->toDateString();
        $clientId = $dailyOperationLine->day?->client_id;

        if ($dailyOperationLine->source_type !== DailyOperationLine::SOURCE_MANUAL_LINE) {
            $this->lineAutomationService->removeAssociatedAutoLinesForManualLine($dailyOperationLine);
        }

        $dailyOperationLine->delete();
        if ($day !== null) {
            $this->totalsService->syncDay($day->fresh(['lines']), null, null, (int) $request->user()->id);
        }

        return redirect()
            ->route('daily-operations.index', ['date' => $date, 'client_id' => $clientId])
            ->with('status', 'Linea diaria eliminada correctamente.');
    }

    private function selectedDate(Request $request): Carbon
    {
        $date = trim((string) $request->string('date'));

        return $date !== ''
            ? Carbon::parse($date)
            : now()->startOfDay();
    }

    private function firstOrCreateDay(string $operationDate, int $clientId, int $userId): DailyOperationDay
    {
        $normalizedDate = Carbon::parse($operationDate)->toDateString();

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', $normalizedDate)
            ->where('client_id', $clientId)
            ->first();

        if ($day !== null) {
            return $day;
        }

        return DailyOperationDay::query()->create([
            'operation_date' => $normalizedDate,
            'client_id' => $clientId,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    private function selectedClient(Request $request, $clients): ?Client
    {
        $requestedClientId = $request->integer('client_id');

        if ($requestedClientId > 0) {
            return $clients->firstWhere('id', $requestedClientId);
        }

        return $clients->first();
    }

    /**
     * @return array<int, array{type:string, document:string, pallets:int, management:bool, trip:bool}>
     */
    private function billingDetails(DailyOperationDay $day): array
    {
        $day->loadMissing('lines');
        $lines = $day->lines;

        return $lines
            ->filter(fn (DailyOperationLine $line): bool => in_array($line->section, [
                DailyOperationLine::SECTION_DESCARGA,
                DailyOperationLine::SECTION_CARGA,
                DailyOperationLine::SECTION_ENVIO,
            ], true))
            ->map(function (DailyOperationLine $line) use ($lines): array {
                $sameSource = fn (DailyOperationLine $candidate): bool => $line->source_type !== null
                    && $line->source_id !== null
                    && $candidate->source_type === $line->source_type
                    && (int) $candidate->source_id === (int) $line->source_id;

                $management = $lines->contains(fn (DailyOperationLine $candidate): bool => $sameSource($candidate)
                    && $candidate->section === DailyOperationLine::SECTION_GESTION_CAMION);
                $trip = $lines->contains(fn (DailyOperationLine $candidate): bool => $sameSource($candidate)
                    && $candidate->section === DailyOperationLine::SECTION_VIAJE_CAMION);

                return [
                    'type' => $line->section === DailyOperationLine::SECTION_DESCARGA ? 'Entrada' : 'Salida',
                    'document' => $this->billingDocumentLabel($line),
                    'pallets' => (int) $line->pallets,
                    'management' => $management || $line->requiresTruckManagement(),
                    'trip' => $trip,
                ];
            })
            ->values()
            ->all();
    }

    private function billingDocumentLabel(DailyOperationLine $line): string
    {
        return match ($line->source_type) {
            DailyOperationLine::SOURCE_GOODS_RECEIPT => 'Entrada #'.$line->source_id,
            DailyOperationLine::SOURCE_GOODS_DISPATCH => 'Salida #'.$line->source_id,
            default => $line->counterparty_name,
        };
    }
}
