<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\Role;
use App\Services\GoodsReceipts\GoodsReceiptDocumentStorage;
use App\Support\GoodsReceipts\DocumentDisplayNamer;
use App\Support\WmsNavigation;
use Illuminate\Http\Request;
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
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->hasRole(Role::CLIENTE), 403);

        $clientId = $user->client_id;
        $month = trim((string) $request->string('month'));
        $supplierId = $request->integer('supplier_id');
        $search = trim((string) $request->string('search'));

        $allDocuments = $clientId === null
            ? collect()
            : GoodsReceipt::query()
                ->where('client_id', $clientId)
                ->whereNotNull('document_path')
                ->with('supplier')
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->get();

        $availableMonths = $allDocuments
            ->filter(fn (GoodsReceipt $receipt) => $receipt->received_at !== null)
            ->map(fn (GoodsReceipt $receipt) => $receipt->received_at->format('Y-m'))
            ->unique()
            ->sortDesc()
            ->values()
            ->map(fn (string $key) => ['value' => $key, 'label' => $this->monthLabel($key)]);

        $availableSuppliers = $allDocuments
            ->pluck('supplier')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        $filtered = $allDocuments
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

        $displayNames = DocumentDisplayNamer::assignNames($filtered);

        $groups = $filtered
            ->groupBy(fn (GoodsReceipt $receipt) => $receipt->received_at?->format('Y-m') ?? 'sin-fecha')
            ->sortKeysDesc()
            ->map(fn ($documents, string $key) => [
                'label' => $key === 'sin-fecha' ? 'Sin fecha' : $this->monthLabel($key),
                'receipts' => $documents,
            ]);

        return view('client.goods-receipts.index', [
            'groups' => $groups,
            'displayNames' => $displayNames,
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
        abort_if($goodsReceipt->document_path === null, 404);

        $disk = $this->documentStorage->resolveDisk($goodsReceipt->document_path);

        abort_if($disk === null, 404);

        $extension = pathinfo((string) $goodsReceipt->document_original_name, PATHINFO_EXTENSION);
        $displayName = DocumentDisplayNamer::baseName($goodsReceipt).($extension !== '' ? '.'.$extension : '');

        return Storage::disk($disk)->download($goodsReceipt->document_path, $displayName);
    }

    private function monthLabel(string $yearMonth): string
    {
        [$year, $month] = array_pad(explode('-', $yearMonth), 2, null);

        if ($year === null || $month === null) {
            return $yearMonth;
        }

        return (self::SPANISH_MONTHS[(int) $month] ?? $month).' '.$year;
    }
}
