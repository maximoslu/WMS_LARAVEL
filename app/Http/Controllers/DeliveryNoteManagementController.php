<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\GoodsReceipt;
use App\Models\Role;
use App\Models\Supplier;
use App\Support\GoodsReceipts\DocumentDisplayNamer;
use App\Support\WmsNavigation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeliveryNoteManagementController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user?->canAccessRole(Role::ADMINISTRACION), 403);

        $validated = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'type' => ['nullable', Rule::in(['all', 'entry', 'dispatch'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'dispatch_status' => ['nullable', Rule::in([GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $filters = [
            'client_id' => (int) ($validated['client_id'] ?? 0),
            'type' => $validated['type'] ?? 'all',
            'date_from' => trim((string) ($validated['date_from'] ?? '')),
            'date_to' => trim((string) ($validated['date_to'] ?? '')),
            'supplier_id' => (int) ($validated['supplier_id'] ?? 0),
            'dispatch_status' => $validated['dispatch_status'] ?? '',
            'search' => trim((string) ($validated['search'] ?? '')),
        ];

        $clients = Client::query()
            ->orderBy('name')
            ->get();

        $suppliers = $filters['client_id'] > 0
            ? Supplier::query()
                ->where(function ($query) use ($filters): void {
                    $query->where('client_id', $filters['client_id'])
                        ->orWhereNull('client_id');
                })
                ->orderBy('name')
                ->get()
            : collect();

        $documents = $filters['client_id'] > 0
            ? $this->documentsForFilters($filters)
            : collect();

        $documents = $this->paginateCollection(
            $documents->sortByDesc(fn (array $document): int => $document['sort_timestamp'])->values(),
            20,
            $request,
        );

        return view('delivery-notes.management.index', [
            'clients' => $clients,
            'suppliers' => $suppliers,
            'documents' => $documents,
            'filters' => $filters,
            'typeOptions' => [
                'all' => 'Todos',
                'entry' => 'Albaranes de entrada',
                'dispatch' => 'Albaranes de salida',
            ],
            'dispatchStatuses' => [
                GoodsDispatch::STATUS_SENT => GoodsDispatch::statusOptions()[GoodsDispatch::STATUS_SENT],
                GoodsDispatch::STATUS_COMPLETED => GoodsDispatch::statusOptions()[GoodsDispatch::STATUS_COMPLETED],
            ],
            'hasClientFilter' => $filters['client_id'] > 0,
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function documentsForFilters(array $filters): Collection
    {
        $documents = collect();

        if (in_array($filters['type'], ['all', 'entry'], true)) {
            $documents = $documents->merge($this->entryDocuments($filters));
        }

        if (in_array($filters['type'], ['all', 'dispatch'], true)) {
            $documents = $documents->merge($this->dispatchDocuments($filters));
        }

        if ($filters['search'] !== '') {
            $needle = mb_strtolower((string) $filters['search']);

            $documents = $documents->filter(
                fn (array $document): bool => str_contains(mb_strtolower($document['search_text']), $needle)
            );
        }

        return $documents->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function entryDocuments(array $filters): Collection
    {
        $receipts = GoodsReceipt::query()
            ->where('client_id', $filters['client_id'])
            ->whereNotNull('document_path')
            ->with(['client', 'supplier'])
            ->when($filters['supplier_id'] > 0, fn ($query) => $query->where('supplier_id', $filters['supplier_id']))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('received_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('received_at', '<=', $filters['date_to']))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get();

        $displayNames = DocumentDisplayNamer::assignNames($receipts);

        return $receipts->map(function (GoodsReceipt $receipt) use ($displayNames): array {
            $displayName = $displayNames[$receipt->id] ?? DocumentDisplayNamer::baseName($receipt);
            $date = $receipt->received_at;
            $number = $receipt->receipt_number ?: 'Entrada #'.$receipt->id;

            return [
                'type' => 'entry',
                'type_label' => 'Entrada',
                'client' => $receipt->client?->name ?? 'Sin cliente',
                'date' => $date,
                'date_label' => $date?->format('d/m/Y') ?? 'Pendiente',
                'display_name' => $displayName,
                'number' => $number,
                'supplier' => $receipt->supplier?->name,
                'status' => null,
                'related' => $receipt->external_document_number,
                'download_url' => route('goods-receipts.document', $receipt),
                'detail_url' => route('goods-receipts.show', $receipt),
                'request_url' => null,
                'sort_timestamp' => $date?->timestamp ?? 0,
                'search_text' => implode(' ', array_filter([
                    'Entrada',
                    $receipt->client?->name,
                    $receipt->client?->code,
                    $number,
                    $receipt->external_document_number,
                    $receipt->document_original_name,
                    $receipt->supplier?->name,
                    $displayName,
                ])),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function dispatchDocuments(array $filters): Collection
    {
        $dispatches = GoodsDispatch::query()
            ->where('client_id', $filters['client_id'])
            ->whereIn('status', [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED])
            ->with(['client', 'merchandiseRequest'])
            ->when($filters['dispatch_status'] !== '', fn ($query) => $query->where('status', $filters['dispatch_status']))
            ->orderByDesc('completed_at')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get()
            ->filter(function (GoodsDispatch $dispatch) use ($filters): bool {
                $date = $this->dispatchDocumentDate($dispatch);

                if ($filters['date_from'] !== '' && ($date === null || $date->lt(Carbon::parse($filters['date_from'])->startOfDay()))) {
                    return false;
                }

                if ($filters['date_to'] !== '' && ($date === null || $date->gt(Carbon::parse($filters['date_to'])->endOfDay()))) {
                    return false;
                }

                return true;
            })
            ->values();

        $displayNames = DocumentDisplayNamer::assignDispatchNames($dispatches);

        return $dispatches->map(function (GoodsDispatch $dispatch) use ($displayNames): array {
            $displayName = $displayNames[$dispatch->id] ?? DocumentDisplayNamer::dispatchBaseName($dispatch);
            $date = $this->dispatchDocumentDate($dispatch);
            $request = $dispatch->merchandiseRequest;
            $related = $request?->delivery_reference
                ?: $request?->delivery_address
                ?: $request?->referenceCode();

            return [
                'type' => 'dispatch',
                'type_label' => 'Salida',
                'client' => $dispatch->client?->name ?? 'Sin cliente',
                'date' => $date,
                'date_label' => $date?->format('d/m/Y') ?? 'Pendiente',
                'display_name' => $displayName,
                'number' => $dispatch->dispatchNumber(),
                'supplier' => null,
                'status' => $dispatch->statusLabel(),
                'related' => $related,
                'download_url' => route('dispatches.delivery-note', $dispatch),
                'detail_url' => route('dispatches.show', $dispatch),
                'request_url' => $request !== null ? route('merchandise-requests.show', $request) : null,
                'sort_timestamp' => $date?->timestamp ?? 0,
                'search_text' => implode(' ', array_filter([
                    'Salida',
                    $dispatch->client?->name,
                    $dispatch->client?->code,
                    $dispatch->dispatchNumber(),
                    $dispatch->statusLabel(),
                    $request?->referenceCode(),
                    $request?->delivery_reference,
                    $request?->delivery_address,
                    $dispatch->client?->formattedDeliveryAddress(),
                    $displayName,
                ])),
            ];
        });
    }

    private function dispatchDocumentDate(GoodsDispatch $dispatch): ?Carbon
    {
        return $dispatch->completed_at ?? $dispatch->sent_at ?? $dispatch->created_at;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $documents
     * @return LengthAwarePaginator<array<string, mixed>>
     */
    private function paginateCollection(Collection $documents, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max(1, $request->integer('page', 1));
        $total = $documents->count();

        if ($total > 0) {
            $page = min($page, (int) ceil($total / $perPage));
        }

        return (new LengthAwarePaginator(
            $documents->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => $request->url()],
        ))->appends($request->except('page'));
    }
}
