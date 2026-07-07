<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyGoodsReceiptAiProposalRequest;
use App\Http\Requests\AttachGoodsReceiptDocumentRequest;
use App\Http\Requests\QuickCreateGoodsReceiptItemRequest;
use App\Http\Requests\StoreGoodsReceiptRequest;
use App\Http\Requests\UpdateGoodsReceiptRequest;
use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\Supplier;
use App\Services\GoodsReceipts\GoodsReceiptAiExtractionService;
use App\Services\GoodsReceipts\GoodsReceiptConfirmationService;
use App\Services\GoodsReceipts\GoodsReceiptDeletionService;
use App\Services\GoodsReceipts\GoodsReceiptDocumentStorage;
use App\Services\GoodsReceipts\GoodsReceiptItemResolver;
use App\Support\WmsNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceiptConfirmationService $confirmationService,
        private readonly GoodsReceiptDeletionService $deletionService,
        private readonly GoodsReceiptItemResolver $itemResolver,
        private readonly GoodsReceiptAiExtractionService $aiExtractionService,
        private readonly GoodsReceiptDocumentStorage $documentStorage,
    ) {}

    public function index(Request $request): View
    {
        $clientFilter = $request->integer('client_id');
        $supplierFilter = $request->integer('supplier_id');
        $status = (string) $request->string('status', 'all');
        $search = trim((string) $request->string('search'));
        $dateFrom = trim((string) $request->string('date_from'));
        $dateTo = trim((string) $request->string('date_to'));

        $receipts = GoodsReceipt::query()
            ->with(['client', 'supplier', 'creator'])
            ->withCount(['lines', 'stockPallets'])
            ->when($clientFilter > 0, fn ($query) => $query->where('client_id', $clientFilter))
            ->when($supplierFilter > 0, fn ($query) => $query->where('supplier_id', $supplierFilter))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('receipt_number', 'like', '%'.$search.'%')
                        ->orWhere('external_document_number', 'like', '%'.$search.'%')
                        ->orWhereHas('supplier', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($dateFrom !== '', fn ($query) => $query->whereDate('received_at', '>=', $dateFrom))
            ->when($dateTo !== '', fn ($query) => $query->whereDate('received_at', '<=', $dateTo))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('goods-receipts.index', [
            'receipts' => $receipts,
            'clients' => Client::query()->orderBy('name')->get(),
            'suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get(),
            'filters' => [
                'client_id' => $clientFilter > 0 ? $clientFilter : null,
                'supplier_id' => $supplierFilter > 0 ? $supplierFilter : null,
                'status' => in_array($status, ['all', GoodsReceipt::STATUS_DRAFT, GoodsReceipt::STATUS_PENDING_REVIEW, GoodsReceipt::STATUS_CONFIRMED, GoodsReceipt::STATUS_CANCELLED], true)
                    ? $status
                    : 'all',
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('goods-receipts.create', $this->formData($request, new GoodsReceipt([
            'status' => GoodsReceipt::STATUS_DRAFT,
            'received_at' => today(),
        ])));
    }

    public function quickCreateItem(QuickCreateGoodsReceiptItemRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->itemResolver->createOrReuseForQuickAdd(
                (int) $validated['client_id'],
                (string) $validated['sku'],
                (string) $validated['description'],
                (int) $validated['units_per_pallet'],
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'No se pudo crear el articulo.',
            ], 422);
        }

        $item = $result['item'];

        return response()->json([
            'created' => $result['created'],
            'message' => $result['created']
                ? 'Articulo creado y seleccionado para esta linea.'
                : 'Ya existia un articulo con este SKU para este cliente. Se ha seleccionado el existente.',
            'item' => [
                'id' => $item->id,
                'sku' => $item->sku,
                'description' => $item->description,
                'units_per_pallet' => $item->units_per_pallet,
                'default_location_id' => $item->default_location_id,
                'client_id' => $item->client_id,
                'status' => $item->status,
                'label' => $item->sku.' - '.$item->description,
            ],
        ], $result['created'] ? 201 : 200);
    }

    public function store(StoreGoodsReceiptRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $expectsAiFlow = $request->expectsAiCreationFlow();

        $receipt = DB::transaction(function () use ($request, $validated): GoodsReceipt {
            $receipt = GoodsReceipt::query()->create([
                'client_id' => $validated['client_id'],
                'supplier_id' => $validated['supplier_id'] ?? null,
                'receipt_number' => $validated['receipt_number'] ?? null,
                'external_document_number' => $validated['external_document_number'] ?? null,
                'status' => GoodsReceipt::STATUS_DRAFT,
                'received_at' => $validated['received_at'] ?? today()->toDateString(),
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->id,
                ...($request->file('document') !== null
                    ? $this->documentStorage->store($request->file('document'))
                    : []),
            ]);

            $this->syncLines($receipt, $validated['lines']);

            return $receipt;
        });

        if ($expectsAiFlow) {
            if ($receipt->document_path === null) {
                return redirect()
                    ->route('goods-receipts.show', $receipt)
                    ->with('status', 'Entrada creada correctamente como borrador.')
                    ->withErrors([
                        'goods_receipt' => 'Adjunta un albaran para interpretar la entrada con IA.',
                    ]);
            }

            if (! config('services.openai.receipt_enabled', false)) {
                return redirect()
                    ->route('goods-receipts.show', $receipt)
                    ->with('status', 'Entrada creada. La IA esta desactivada ahora mismo; puedes completar la entrada manualmente.');
            }

            try {
                $this->performAiExtraction($receipt);
            } catch (\Throwable $exception) {
                return redirect()
                    ->route('goods-receipts.show', $receipt)
                    ->with('status', 'Entrada creada, pero no se pudo interpretar el documento con IA. Puedes rellenar las lineas manualmente o reintentar.')
                    ->withErrors([
                        'goods_receipt' => 'La interpretacion IA no pudo completarse. Revisa el error y vuelve a intentarlo.',
                    ]);
            }

            return redirect()
                ->route('goods-receipts.show', $receipt)
                ->with('status', 'Entrada creada e interpretada con IA. Revisa la propuesta antes de aplicarla.');
        }

        return redirect()
            ->route('goods-receipts.show', $receipt)
            ->with('status', 'Entrada creada correctamente como borrador.');
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): View
    {
        $goodsReceipt->load([
            'client',
            'supplier',
            'creator',
            'confirmer',
            'lines.item',
            'lines.location',
            'stockPallets.item',
            'stockPallets.location',
        ]);

        return view('goods-receipts.show', [
            'receipt' => $goodsReceipt,
            'locations' => Location::query()->where('active', true)->with('warehouse')->orderBy('code')->get(),
            'suppliers' => Supplier::query()
                ->where('active', true)
                ->where(function ($query) use ($goodsReceipt): void {
                    $query
                        ->whereNull('client_id')
                        ->orWhere('client_id', $goodsReceipt->client_id);
                })
                ->orderBy('name')
                ->get(),
            'aiEnabled' => (bool) config('services.openai.receipt_enabled', false),
            'lineValues' => $this->lineValues($request, $goodsReceipt),
            'searchEndpoint' => route('ajax.items'),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function edit(Request $request, GoodsReceipt $goodsReceipt): View
    {
        abort_if($goodsReceipt->isConfirmed(), 403);

        $goodsReceipt->load('lines');

        return view('goods-receipts.edit', $this->formData($request, $goodsReceipt));
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        abort_if($goodsReceipt->isConfirmed(), 403);

        $validated = $request->validated();

        DB::transaction(function () use ($request, $validated, $goodsReceipt): void {
            $payload = [
                'client_id' => $validated['client_id'],
                'supplier_id' => $validated['supplier_id'] ?? null,
                'receipt_number' => $validated['receipt_number'] ?? null,
                'external_document_number' => $validated['external_document_number'] ?? null,
                'received_at' => $validated['received_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ];

            if ($request->hasFile('document')) {
                $payload = [
                    ...$payload,
                    ...$this->documentStorage->store($request->file('document'), $goodsReceipt),
                ];
            }

            $goodsReceipt->update($payload);
            $this->syncLines($goodsReceipt, $validated['lines']);
        });

        return redirect()
            ->route('goods-receipts.show', $goodsReceipt)
            ->with('status', 'Entrada actualizada correctamente.');
    }

    public function confirm(GoodsReceipt $goodsReceipt, Request $request): RedirectResponse
    {
        try {
            $this->confirmationService->confirm($goodsReceipt, $request->user());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('goods-receipts.show', $goodsReceipt)
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('goods-receipts.show', $goodsReceipt)
            ->with('status', 'Entrada confirmada y stock actualizado correctamente.');
    }

    public function cancel(GoodsReceipt $goodsReceipt): RedirectResponse
    {
        if ($goodsReceipt->status === GoodsReceipt::STATUS_CONFIRMED) {
            return redirect()
                ->route('goods-receipts.show', $goodsReceipt)
                ->withErrors([
                    'goods_receipt' => 'La cancelacion de entradas confirmadas con reversa de stock queda pendiente de una fase posterior.',
                ]);
        }

        if ($goodsReceipt->status !== GoodsReceipt::STATUS_CANCELLED) {
            $goodsReceipt->update([
                'status' => GoodsReceipt::STATUS_CANCELLED,
            ]);
        }

        return redirect()
            ->route('goods-receipts.show', $goodsReceipt)
            ->with('status', 'Entrada cancelada correctamente.');
    }

    public function attachDocument(AttachGoodsReceiptDocumentRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $goodsReceipt->update($this->documentStorage->store($request->file('document'), $goodsReceipt));

        return redirect()
            ->route('goods-receipts.show', $goodsReceipt)
            ->with('status', 'Documento adjuntado correctamente.');
    }

    public function destroy(GoodsReceipt $goodsReceipt, Request $request): RedirectResponse
    {
        try {
            $this->deletionService->delete($goodsReceipt, $request->user());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('goods-receipts.index')
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('goods-receipts.index')
            ->with('status', 'Entrada borrada correctamente.');
    }

    public function extractAi(GoodsReceipt $goodsReceipt): RedirectResponse
    {
        if ($goodsReceipt->isConfirmed() || $goodsReceipt->status === GoodsReceipt::STATUS_CANCELLED) {
            return redirect()
                ->route('goods-receipts.show', $goodsReceipt)
                ->withErrors([
                    'goods_receipt' => 'Solo se puede interpretar con IA una entrada en borrador o pendiente de revision.',
                ]);
        }

        if ($goodsReceipt->document_path === null) {
            return redirect()
                ->route('goods-receipts.show', $goodsReceipt)
                ->withErrors([
                    'goods_receipt' => 'Adjunta un albaran o documento del proveedor para poder interpretarlo con IA.',
                ]);
        }

        try {
            $this->performAiExtraction($goodsReceipt);
        } catch (\Throwable $exception) {
            return redirect()
                ->route('goods-receipts.show', $goodsReceipt)
                ->withErrors([
                    'goods_receipt' => 'La interpretacion IA no pudo completarse. Revisa el error y vuelve a intentarlo.',
                ]);
        }

        return redirect()
            ->route('goods-receipts.show', $goodsReceipt)
            ->with('status', 'Documento interpretado con IA. Revisa la propuesta antes de aplicarla.');
    }

    public function applyAi(ApplyGoodsReceiptAiProposalRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        if ($goodsReceipt->isConfirmed() || $goodsReceipt->status === GoodsReceipt::STATUS_CANCELLED) {
            return redirect()
                ->route('goods-receipts.show', $goodsReceipt)
                ->withErrors([
                    'goods_receipt' => 'No se puede aplicar una propuesta IA sobre una entrada confirmada o cancelada.',
                ]);
        }

        if (! is_array($goodsReceipt->ai_extracted_data) || $goodsReceipt->ai_extracted_data === []) {
            return redirect()
                ->route('goods-receipts.show', $goodsReceipt)
                ->withErrors([
                    'goods_receipt' => 'No hay una propuesta IA disponible para aplicar en esta entrada.',
                ]);
        }

        $validated = $request->validated();

        DB::transaction(function () use ($goodsReceipt, $validated, $request): void {
            $this->syncLines($goodsReceipt, $validated['lines']);

            $currentAiData = is_array($goodsReceipt->ai_extracted_data) ? $goodsReceipt->ai_extracted_data : [];

            $goodsReceipt->update([
                'supplier_id' => $validated['supplier_id'] ?? null,
                'receipt_number' => $validated['receipt_number'] ?? null,
                'received_at' => $validated['received_at'] ?? null,
                'ai_status' => GoodsReceipt::AI_STATUS_REVIEWED,
                'ai_error' => null,
                'ai_extracted_data' => [
                    ...$currentAiData,
                    'reviewed_payload' => [
                        'supplier_id' => $validated['supplier_id'] ?? null,
                        'receipt_number' => $validated['receipt_number'] ?? null,
                        'received_at' => $validated['received_at'] ?? null,
                        'lines' => $validated['lines'],
                    ],
                    'applied_at' => now()->toIso8601String(),
                    'applied_by' => $request->user()->id,
                ],
            ]);
        });

        return redirect()
            ->route('goods-receipts.show', $goodsReceipt)
            ->with('status', 'Propuesta IA aplicada correctamente a la entrada. Revisa y confirma cuando proceda.');
    }

    public function downloadDocument(GoodsReceipt $goodsReceipt): StreamedResponse
    {
        abort_if($goodsReceipt->document_path === null, 404);

        $disk = $this->documentStorage->resolveDisk($goodsReceipt->document_path);

        abort_if($disk === null, 404);

        return Storage::disk($disk)->download(
            $goodsReceipt->document_path,
            $goodsReceipt->document_original_name ?: basename($goodsReceipt->document_path)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, GoodsReceipt $receipt): array
    {
        return [
            'receipt' => $receipt,
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get(),
            'locations' => Location::query()->where('active', true)->with('warehouse')->orderBy('code')->get(),
            'lineValues' => $this->lineValues($request, $receipt),
            'searchEndpoint' => route('ajax.items'),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lineValues(Request $request, GoodsReceipt $receipt): array
    {
        $oldLines = $request->old('lines');

        if (is_array($oldLines) && $oldLines !== []) {
            return array_values($oldLines);
        }

        if ($receipt->exists) {
            $lines = $receipt->lines
                ->map(fn (GoodsReceiptLine $line): array => [
                    'item_id' => $line->item_id,
                    'item_search' => $line->item ? $line->item->sku.' - '.$line->item->description : null,
                    'sku' => $line->sku,
                    'description' => $line->description,
                    'lot' => $line->lot,
                    'quantity_units' => $line->quantity_units,
                    'units_per_pallet' => $line->units_per_pallet,
                    'pallet_count' => $line->pallet_count,
                    'pico_units' => $line->pico_units,
                    'location_id' => $line->location_id,
                ])
                ->all();

            if ($lines !== []) {
                return $lines;
            }
        }

        return [[
            'item_id' => null,
            'item_search' => null,
            'sku' => null,
            'description' => null,
            'lot' => null,
            'quantity_units' => null,
            'units_per_pallet' => null,
            'pallet_count' => null,
            'pico_units' => null,
            'location_id' => null,
        ]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncLines(GoodsReceipt $receipt, array $lines): void
    {
        $receipt->lines()->delete();

        foreach ($lines as $line) {
            $item = $this->itemResolver->resolveForPayload((int) $receipt->client_id, $line);

            $receipt->lines()->create([
                'item_id' => $item?->id ?? null,
                'sku' => $item?->sku ?? ($line['sku'] ?? null),
                'description' => $item?->description ?? ($line['description'] ?? null),
                'lot' => $line['lot'] ?? null,
                'quantity_units' => $line['quantity_units'] ?? 0,
                'units_per_pallet' => $line['units_per_pallet'] ?? $item?->units_per_pallet,
                'pallet_count' => $line['pallet_count'] ?? 0,
                'pico_units' => $line['pico_units'] ?? null,
                'location_id' => $line['location_id'] ?? null,
            ]);
        }
    }

    private function performAiExtraction(GoodsReceipt $goodsReceipt): void
    {
        $goodsReceipt->update([
            'ai_status' => GoodsReceipt::AI_STATUS_PROCESSING,
            'ai_error' => null,
        ]);

        try {
            $result = $this->aiExtractionService->extractFromDocument($goodsReceipt);

            $goodsReceipt->update([
                'ai_status' => GoodsReceipt::AI_STATUS_COMPLETED,
                'ai_extracted_data' => $result->toArray(),
                'ai_error' => null,
                'document_processed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            $goodsReceipt->update([
                'ai_status' => GoodsReceipt::AI_STATUS_FAILED,
                'ai_error' => Str::limit(trim($exception->getMessage()) ?: 'No se pudo interpretar el documento.', 500),
            ]);

            throw $exception;
        }
    }

}
