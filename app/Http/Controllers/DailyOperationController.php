<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDailyOperationLineRequest;
use App\Http\Requests\UpdateDailyOperationLineRequest;
use App\Http\Requests\UpsertDailyOperationDayRequest;
use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\Role;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DailyOperationController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);

        $selectedDate = $this->selectedDate($request);

        $day = DailyOperationDay::query()
            ->with(['creator', 'updater', 'lines.creator'])
            ->whereDate('operation_date', $selectedDate->toDateString())
            ->first();

        $lineBeingEdited = null;
        $editLineId = $request->integer('edit_line');

        if ($day !== null && $editLineId > 0) {
            $lineBeingEdited = $day->lines->firstWhere('id', $editLineId);
        }

        return view('daily-operations.index', [
            'day' => $day,
            'selectedDate' => $selectedDate,
            'sectionOptions' => DailyOperationLine::sectionOptions(),
            'sectionTotals' => $day?->sectionTotals() ?? collect(DailyOperationLine::sections())->mapWithKeys(fn (string $section) => [$section => 0])->all(),
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
        ]);

        if (! $day->exists) {
            $day->created_by = $request->user()->id;
        }

        $day->fill([
            'opening_pallets' => $validated['opening_pallets'] ?? null,
            'stored_pallets_today' => $validated['stored_pallets_today'] ?? null,
            'moved_pallets_today' => $validated['moved_pallets_today'] ?? null,
            'expected_pallets_tomorrow' => $validated['expected_pallets_tomorrow'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'updated_by' => $request->user()->id,
        ])->save();

        return redirect()
            ->route('daily-operations.index', ['date' => $validated['operation_date']])
            ->with('status', 'Resumen diario guardado correctamente.');
    }

    public function storeLine(StoreDailyOperationLineRequest $request): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);

        $validated = $request->validated();
        $day = $this->firstOrCreateDay($validated['operation_date'], (int) $request->user()->id);

        $sortOrder = (int) $day->lines()->max('sort_order') + 1;

        $day->lines()->create([
            'section' => $validated['section'],
            'counterparty_name' => $validated['counterparty_name'],
            'pallets' => $validated['pallets'],
            'observations' => $validated['observations'] ?? null,
            'without_booking' => false,
            'sort_order' => $sortOrder,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('daily-operations.index', ['date' => $validated['operation_date']])
            ->with('status', 'Linea diaria guardada correctamente.');
    }

    public function updateLine(UpdateDailyOperationLineRequest $request, DailyOperationLine $dailyOperationLine): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);

        $validated = $request->validated();
        $targetDate = $validated['operation_date'] ?? $dailyOperationLine->day?->operation_date?->toDateString() ?? now()->toDateString();

        $day = $this->firstOrCreateDay($targetDate, (int) $request->user()->id);

        $dailyOperationLine->update([
            'day_id' => $day->id,
            'section' => $validated['section'],
            'counterparty_name' => $validated['counterparty_name'],
            'pallets' => $validated['pallets'],
            'observations' => $validated['observations'] ?? null,
        ]);

        return redirect()
            ->route('daily-operations.index', ['date' => $targetDate])
            ->with('status', 'Linea diaria actualizada correctamente.');
    }

    public function destroyLine(Request $request, DailyOperationLine $dailyOperationLine): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);

        $date = $dailyOperationLine->day?->operation_date?->toDateString() ?? now()->toDateString();
        $dailyOperationLine->delete();

        return redirect()
            ->route('daily-operations.index', ['date' => $date])
            ->with('status', 'Linea diaria eliminada correctamente.');
    }

    private function selectedDate(Request $request): Carbon
    {
        $date = trim((string) $request->string('date'));

        return $date !== ''
            ? Carbon::parse($date)
            : now()->startOfDay();
    }

    private function firstOrCreateDay(string $operationDate, int $userId): DailyOperationDay
    {
        $normalizedDate = Carbon::parse($operationDate)->toDateString();

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', $normalizedDate)
            ->first();

        if ($day !== null) {
            return $day;
        }

        return DailyOperationDay::query()->create([
            'operation_date' => $normalizedDate,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }
}
