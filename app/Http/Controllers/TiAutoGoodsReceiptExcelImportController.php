<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogService;
use App\Services\GoodsReceipts\TiAutoGoodsReceiptExcelImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TiAutoGoodsReceiptExcelImportController extends Controller
{
    public function __construct(
        private readonly TiAutoGoodsReceiptExcelImportService $importer,
        private readonly AuditLogService $audit,
    ) {}

    public function create(): View
    {
        return view('goods-receipts.ti-auto-excel');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'excel' => ['required', 'file', 'mimes:xlsx', 'max:'.config('wms.stock_imports.max_file_kilobytes', 2048)],
        ], [
            'excel.required' => 'Selecciona el Excel de TI Auto.',
            'excel.mimes' => 'La entrada desde Excel solo admite archivos .xlsx.',
        ]);

        $receipt = $this->importer->createDraft($validated['excel'], $request->user());
        $receipt->loadCount('lines');

        $this->audit->record(
            event: 'goods_receipt_ti_auto_excel_imported',
            module: 'goods_receipts',
            description: 'Entrada de TI Auto creada desde Excel como borrador.',
            auditable: $receipt,
            user: $request->user(),
            clientId: $receipt->client_id,
            newValues: $receipt->only(['receipt_number', 'external_document_number', 'received_at', 'status']),
            metadata: ['lines' => $receipt->lines_count],
        );

        return redirect()
            ->route('goods-receipts.show', $receipt)
            ->with('status', 'Entrada de TI Auto creada desde Excel como borrador. Revisa y confirma la entrada para aplicar el stock.');
    }
}
