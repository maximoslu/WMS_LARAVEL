<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachGoodsReceiptDocumentRequest;
use App\Http\Requests\StoreGoodsReceiptRequest;
use App\Http\Requests\UpdateGoodsReceiptRequest;
use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\Supplier;
use App\Services\GoodsReceipts\GoodsReceiptConfirmationService;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceiptConfirmationService $confirmationService,
    ) {}

    public function index(Request $request): View
    {
        $clientFilter = $request->integer('client_id');
        $supplierFilter = $request->integer('supplier_id');
        $status = (string) $request->string('status', GoodsReceipt::STATUS_DRAFT);
        $search = trim((string) $request->string('search'));

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
            ->latest('id')
            ->paginate(20)
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
                    : GoodsReceipt::STATUS_DRAFT,
                'search' => $search,
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

    public function store(StoreGoodsReceiptRequest $request): RedirectResponse
    {
        $validated = $request->validated();

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
                ...$this->documentPayload($request->file('document')),
            ]);

            $this->syncLines($receipt, $validated['lines']);

            return $receipt;
        });

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
                    ...$this->documentPayload($request->file('document'), $goodsReceipt),
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
            ->with('status', 'Entrada confirmada y stock generado correctamente.');
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
        $goodsReceipt->update($this->documentPayload($request->file('document'), $goodsReceipt));

        return redirect()
            ->route('goods-receipts.show', $goodsReceipt)
            ->with('status', 'Documento adjuntado correctamente.');
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
            'items' => Item::query()->where('active', true)->with('client')->orderBy('sku')->get(),
            'itemsCatalog' => Item::query()
                ->where('active', true)
                ->orderBy('sku')
                ->get()
                ->map(fn (Item $item): array => [
                    'id' => $item->id,
                    'client_id' => $item->client_id,
                    'sku' => $item->sku,
                    'description' => $item->description,
                    'lot' => $item->lot,
                    'units_per_pallet' => $item->units_per_pallet,
                ])
                ->values()
                ->all(),
            'locations' => Location::query()->where('active', true)->with('warehouse')->orderBy('code')->get(),
            'lineValues' => $this->lineValues($request, $receipt),
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
            return $receipt->lines
                ->map(fn (GoodsReceiptLine $line): array => [
                    'item_id' => $line->item_id,
                    'sku' => $line->sku,
                    'description' => $line->description,
                    'lot' => $line->lot,
                    'quantity_units' => $line->quantity_units,
                    'units_per_pallet' => $line->units_per_pallet,
                    'pallet_count' => $line->pallet_count,
                    'pico_units' => $line->pico_units,
                    'location_id' => $line->location_id,
                    'notes' => $line->notes,
                ])
                ->all();
        }

        return [[
            'item_id' => null,
            'sku' => null,
            'description' => null,
            'lot' => null,
            'quantity_units' => null,
            'units_per_pallet' => null,
            'pallet_count' => null,
            'pico_units' => null,
            'location_id' => null,
            'notes' => null,
        ]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncLines(GoodsReceipt $receipt, array $lines): void
    {
        $receipt->lines()->delete();

        foreach ($lines as $line) {
            $receipt->lines()->create([
                'item_id' => $line['item_id'] ?? null,
                'sku' => $line['sku'] ?? null,
                'description' => $line['description'] ?? null,
                'lot' => $line['lot'] ?? null,
                'quantity_units' => $line['quantity_units'] ?? 0,
                'units_per_pallet' => $line['units_per_pallet'] ?? null,
                'pallet_count' => $line['pallet_count'] ?? 0,
                'pico_units' => $line['pico_units'] ?? null,
                'location_id' => $line['location_id'] ?? null,
                'notes' => $line['notes'] ?? null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(?UploadedFile $document, ?GoodsReceipt $receipt = null): array
    {
        if ($document === null) {
            return [];
        }

        if ($receipt?->document_path !== null) {
            Storage::disk('public')->delete($receipt->document_path);
        }

        return [
            'document_path' => $document->store('goods-receipts', 'public'),
            'document_original_name' => $document->getClientOriginalName(),
            'document_mime' => $document->getMimeType(),
            'document_processed_at' => null,
            'ai_status' => null,
            'ai_extracted_data' => null,
            'ai_error' => null,
        ];
    }
}
