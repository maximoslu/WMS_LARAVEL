<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\StockPallet;
use App\Services\Labels\MerchandiseLabelService;
use App\Support\WmsNavigation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class LabelController extends Controller
{
    public function __construct(
        private readonly MerchandiseLabelService $labels,
    ) {}

    public function index(Request $request): View
    {
        return view('labels.index', [
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'recentReceipts' => GoodsReceipt::query()
                ->with(['client', 'supplier'])
                ->latest('id')
                ->limit(8)
                ->get(),
            'recentStock' => StockPallet::query()
                ->with(['client', 'item', 'location.warehouse'])
                ->where('active', true)
                ->latest('id')
                ->limit(8)
                ->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function goodsReceipt(GoodsReceipt $goodsReceipt): Response
    {
        $goodsReceipt->loadMissing('client');

        return $this->download(
            $this->labels->forGoodsReceipt($goodsReceipt),
            $this->labels->filename((string) $goodsReceipt->client?->code, $goodsReceipt->receipt_number ?: 'entrada_'.$goodsReceipt->id),
            'Entrada '.$goodsReceipt->id,
        );
    }

    public function goodsReceiptLine(GoodsReceipt $goodsReceipt, GoodsReceiptLine $line): Response
    {
        abort_unless((int) $line->goods_receipt_id === (int) $goodsReceipt->id, 404);

        $goodsReceipt->loadMissing('client');

        return $this->download(
            $this->labels->forGoodsReceiptLine($line),
            $this->labels->filename((string) $goodsReceipt->client?->code, ($goodsReceipt->receipt_number ?: 'entrada_'.$goodsReceipt->id).'_linea_'.$line->id),
            'Linea de entrada '.$line->id,
        );
    }

    public function stockPallet(StockPallet $stockPallet): Response
    {
        $stockPallet->loadMissing(['client', 'item']);

        return $this->download(
            $this->labels->forStockPallet($stockPallet),
            $this->labels->filename((string) $stockPallet->client?->code, 'stock_'.$stockPallet->item?->sku.'_'.$stockPallet->id),
            'Stock '.$stockPallet->id,
        );
    }

    private function download($labels, string $filename, string $origin): Response
    {
        return Pdf::loadView('labels.pdf', [
            'labels' => $labels,
            'origin' => $origin,
            'generatedAt' => now(),
        ])->setPaper('a4')->download($filename);
    }
}
