<?php

namespace App\Http\Controllers;

use App\Models\GoodsDispatch;
use App\Models\GoodsReceipt;
use App\Models\Role;
use App\Services\Audit\AuditLogService;
use App\Services\GoodsDispatches\GoodsDispatchWorkflowService;
use App\Services\GoodsReceipts\GoodsReceiptDocumentStorage;
use App\Support\GoodsReceipts\DocumentDisplayNamer;
use App\Support\WmsNavigation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientGoodsReceiptDocumentController extends Controller
{
    private const SPANISH_MONTHS = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function __construct(
        private readonly GoodsReceiptDocumentStorage $documentStorage,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->hasRole(Role::CLIENTE), 403);

        $clientId = $user->client_id;
        $month = trim((string) $request->string('month'));
        $supplierId = $request->integer('supplier_id');
        $search = trim((string) $request->string('search'));

        $allReceipts = $clientId === null
            ? collect()
            : GoodsReceipt::query()
                ->where('client_id', $clientId)
                ->whereNotNull('document_path')
                ->with('supplier')
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->get();

        $allDispatches = $clientId === null
            ? collect()
            : GoodsDispatch::query()
                ->where('client_id', $clientId)
                ->whereIn('status', [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED])
                ->with(['client', 'merchandiseRequest'])
                ->orderByDesc('completed_at')
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->get();

        $availableMonths = collect($allReceipts
            ->filter(fn (GoodsReceipt $receipt) => $receipt->received_at !== null)
            ->map(fn (GoodsReceipt $receipt) => $receipt->received_at->format('Y-m'))
            ->all())
            ->merge($allDispatches
                ->map(fn (GoodsDispatch $dispatch) => $this->dispatchDocumentDate($dispatch)?->format('Y-m'))
                ->filter())
            ->unique()
            ->sortDesc()
            ->values()
            ->map(fn (string $key) => ['value' => $key, 'label' => $this->monthLabel($key)]);

        $availableSuppliers = $allReceipts
            ->pluck('supplier')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        $filteredReceipts = $allReceipts
            ->when($month !== '', fn ($documents) => $documents->filter(
                fn (GoodsReceipt $receipt) => $receipt->received_at?->format('Y-m') === $month
            ))
            ->when($supplierId > 0, fn ($documents) => $documents->filter(
                fn (GoodsReceipt $receipt) => (int) $receipt->supplier_id === $supplierId
            ))
            ->when($search !== '', function ($documents) use ($search) {
                $needle = mb_strtolower($search);

                return $documents->filter(function (GoodsReceipt $receipt) use ($needle): bool {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $receipt->receipt_number,
                        $receipt->external_document_number,
                        $receipt->document_original_name,
                        $receipt->supplier?->name,
                        DocumentDisplayNamer::baseName($receipt),
                    ])));

                    return str_contains($haystack, $needle);
                });
            })
            ->values();

        $filteredDispatches = $allDispatches
            ->when($month !== '', fn ($documents) => $documents->filter(
                fn (GoodsDispatch $dispatch) => $this->dispatchDocumentDate($dispatch)?->format('Y-m') === $month
            ))
            ->when($search !== '', function ($documents) use ($search) {
                $needle = mb_strtolower($search);

                return $documents->filter(function (GoodsDispatch $dispatch) use ($needle): bool {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $dispatch->dispatchNumber(),
                        $dispatch->statusLabel(),
                        $dispatch->client?->name,
                        $dispatch->client?->code,
                        $dispatch->client?->formattedDeliveryAddress(),
                        $dispatch->merchandiseRequest?->referenceCode(),
                        $dispatch->merchandiseRequest?->delivery_reference,
                        $dispatch->merchandiseRequest?->delivery_address,
                        DocumentDisplayNamer::dispatchBaseName($dispatch),
                    ])));

                    return str_contains($haystack, $needle);
                });
            })
            ->values();

        $displayNames = DocumentDisplayNamer::assignNames($filteredReceipts);
        $dispatchDisplayNames = DocumentDisplayNamer::assignDispatchNames($filteredDispatches);

        $receiptDocuments = $this->paginateCollection(
            $filteredReceipts->sortByDesc(fn (GoodsReceipt $receipt) => $receipt->received_at?->timestamp ?? 0)->values(),
            10,
            'entradas_page',
            $request,
        );
        $dispatchDocuments = $this->paginateCollection(
            $filteredDispatches->sortByDesc(fn (GoodsDispatch $dispatch) => $this->dispatchDocumentDate($dispatch)?->timestamp ?? 0)->values(),
            10,
            'salidas_page',
            $request,
        );

        return view('client.goods-receipts.index', [
            'receiptDocuments' => $receiptDocuments,
            'dispatchDocuments' => $dispatchDocuments,
            'displayNames' => $displayNames,
            'dispatchDisplayNames' => $dispatchDisplayNames,
            'availableMonths' => $availableMonths,
            'availableSuppliers' => $availableSuppliers,
            'filters' => [
                'month' => $month,
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'search' => $search,
            ],
            'hasClient' => $clientId !== null,
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function download(Request $request, GoodsReceipt $goodsReceipt): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user->hasRole(Role::CLIENTE), 403);
        abort_unless($user->client_id !== null && (int) $user->client_id === (int) $goodsReceipt->client_id, 403);

        $response = $this->documentDownloadResponse($goodsReceipt);
        $this->audit->record(event: 'client_receipt_document_downloaded', module: 'documents', description: 'Albaran de entrada descargado por cliente.', auditable: $goodsReceipt, user: $user, clientId: $goodsReceipt->client_id);

        return $response;
    }

    public function downloadSigned(Request $request, GoodsReceipt $goodsReceipt): StreamedResponse
    {
        $user = $request->user();

        if ($user !== null) {
            abort_unless($user->hasRole(Role::CLIENTE), 403);
            abort_unless($user->client_id !== null && (int) $user->client_id === (int) $goodsReceipt->client_id, 403);
        }

        $response = $this->documentDownloadResponse($goodsReceipt);
        $this->audit->record(event: 'signed_receipt_document_downloaded', module: 'documents', description: 'Albaran de entrada descargado mediante enlace firmado.', auditable: $goodsReceipt, user: $user, clientId: $goodsReceipt->client_id, source: 'signed_link');

        return $response;
    }

    private function documentDownloadResponse(GoodsReceipt $goodsReceipt): StreamedResponse
    {
        abort_if($goodsReceipt->document_path === null, 404);

        $disk = $this->documentStorage->resolveDisk($goodsReceipt->document_path);

        abort_if($disk === null, 404);

        $extension = pathinfo((string) $goodsReceipt->document_original_name, PATHINFO_EXTENSION);
        $displayName = DocumentDisplayNamer::baseName($goodsReceipt).($extension !== '' ? '.'.$extension : '');

        return Storage::disk($disk)->download($goodsReceipt->document_path, $displayName);
    }

    public function downloadDispatch(
        Request $request,
        GoodsDispatch $goodsDispatch,
        GoodsDispatchWorkflowService $workflowService,
    ) {
        $user = $request->user();

        abort_unless($user->hasRole(Role::CLIENTE), 403);
        abort_unless($user->client_id !== null && (int) $user->client_id === (int) $goodsDispatch->client_id, 403);
        abort_unless(in_array($goodsDispatch->status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true), 403);

        $goodsDispatch->load(['client', 'merchandiseRequest', 'lines.item', 'lines.stockPallet', 'lines.allocations']);
        $workflowService->ensureDeliveryNoteCanBeGenerated($goodsDispatch);
        $this->audit->record(event: 'client_dispatch_document_downloaded', module: 'documents', description: 'Albaran de salida descargado por cliente.', auditable: $goodsDispatch, user: $user, clientId: $goodsDispatch->client_id);

        return Pdf::loadView('dispatches.delivery-note-pdf', [
            'dispatch' => $goodsDispatch,
        ])->download(DocumentDisplayNamer::dispatchBaseName($goodsDispatch).'.pdf');
    }

    private function monthLabel(string $yearMonth): string
    {
        [$year, $month] = array_pad(explode('-', $yearMonth), 2, null);

        if ($year === null || $month === null) {
            return $yearMonth;
        }

        return (self::SPANISH_MONTHS[(int) $month] ?? $month).' '.$year;
    }

    private function dispatchDocumentDate(GoodsDispatch $dispatch)
    {
        return $dispatch->completed_at ?? $dispatch->sent_at ?? $dispatch->created_at;
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  Collection<TKey, TValue>  $documents
     * @return LengthAwarePaginator<TValue>
     */
    private function paginateCollection(Collection $documents, int $perPage, string $pageName, Request $request): LengthAwarePaginator
    {
        $page = max(1, $request->integer($pageName, 1));
        $total = $documents->count();

        if ($total > 0) {
            $page = min($page, (int) ceil($total / $perPage));
        }

        return (new LengthAwarePaginator(
            $documents->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => $pageName,
            ],
        ))->appends($request->except($pageName));
    }
}
