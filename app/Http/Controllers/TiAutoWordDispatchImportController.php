<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogService;
use App\Services\GoodsDispatches\TiAutoWordDispatchImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TiAutoWordDispatchImportController extends Controller
{
    public function create(): View
    {
        return view('dispatches.ti-auto-word');
    }

    public function store(Request $request, TiAutoWordDispatchImportService $importer, AuditLogService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'word' => ['required', 'file', 'mimes:docx', 'max:2048'],
        ]);

        $dispatch = $importer->createDraft($validated['word'], $request->user());
        $audit->record(
            event: 'goods_dispatch_ti_auto_word_imported',
            module: 'goods_dispatches',
            description: 'Salida TI Auto creada desde Word como borrador.',
            auditable: $dispatch,
            user: $request->user(),
            clientId: $dispatch->client_id,
            metadata: ['lines' => $dispatch->lines()->count()],
        );

        return redirect()->route('dispatches.show', $dispatch)
            ->with('status', 'Borrador creado desde Word. Revisa cada lote, partida y cantidad antes de confirmar la carga.');
    }
}
