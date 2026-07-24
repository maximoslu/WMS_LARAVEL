<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Labels\MerchandiseLabelService;
use Database\Seeders\RoleSeeder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MerchandiseLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_roles_can_access_labels_index(): void
    {
        $this->seed(RoleSeeder::class);

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION, Role::ALMACEN] as $roleSlug) {
            $this->actingAs($this->makeUserWithRole($roleSlug))
                ->get(route('labels.index'))
                ->assertOk()
                ->assertSee('Etiquetas')
                ->assertSee('Etiquetas por hoja');
        }
    }

    public function test_client_cannot_access_labels_or_direct_generation_urls(): void
    {
        $this->seed(RoleSeeder::class);

        [$receipt, $line, $stockPallet, $client] = $this->labelFixtures();
        $user = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($user)->get(route('labels.index'))->assertForbidden();
        $this->actingAs($user)->get(route('labels.goods-receipt', $receipt))->assertForbidden();
        $this->actingAs($user)->get(route('labels.goods-receipt-line', [$receipt, $line]))->assertForbidden();
        $this->actingAs($user)->get(route('labels.stock-pallet', $stockPallet))->assertForbidden();
    }

    public function test_goods_receipt_generates_one_label_per_pallet_and_peak(): void
    {
        [$receipt] = $this->labelFixtures();

        $labels = app(MerchandiseLabelService::class)->forGoodsReceipt($receipt);

        $this->assertCount(11, $labels);
        $this->assertSame(6, $labels->chunk(2)->count());
        $this->assertSame(10, $labels->where('type', 'PALLET')->count());
        $this->assertSame(1, $labels->where('type', 'PICO')->count());
        $this->assertSame('FILM0419 - Film retractil Friesland', $labels->first()['article']);
        $this->assertSame('471543', $labels->first()['lot']);
        $this->assertSame(1600, $labels->first()['units']);
        $this->assertSame(1460, $labels->last()['units']);
    }

    public function test_internal_user_can_download_goods_receipt_labels_pdf(): void
    {
        [$receipt] = $this->labelFixtures();

        $response = $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('labels.goods-receipt', $receipt));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('etiquetas_friesland_gr_000123_', (string) $response->headers->get('content-disposition'));
    }

    public function test_internal_user_can_download_single_goods_receipt_line_labels_pdf(): void
    {
        [$receipt, $line] = $this->labelFixtures();

        $response = $this->actingAs($this->makeUserWithRole(Role::ADMINISTRACION))
            ->get(route('labels.goods-receipt-line', [$receipt, $line]));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }

    public function test_pdf_view_uses_friesland_layout_fields_without_internal_metadata(): void
    {
        [$receipt] = $this->labelFixtures();

        $html = view('labels.pdf', [
            'labels' => app(MerchandiseLabelService::class)->forGoodsReceipt($receipt),
            'origin' => 'Entrada '.$receipt->id,
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('FILM0419', $html);
        $this->assertStringContainsString('LOTE:', $html);
        $this->assertStringContainsString('471543', $html);
        $this->assertStringContainsString('UNIDADES:', $html);
        $this->assertStringContainsString('1.600', $html);
        $this->assertStringContainsString('label-top', $html);
        $this->assertStringContainsString('label-bottom', $html);
        $this->assertSame(6, substr_count($html, 'class="label-page'));
        $this->assertSame(12, substr_count($html, 'class="label-slot'));
        $this->assertStringNotContainsString('MAXIMO WMS - Etiqueta mercancia', $html);
        $this->assertStringNotContainsString('Entrada:', $html);
        $this->assertStringNotContainsString('Fecha:', $html);
        $this->assertStringNotContainsString('entrada-linea:', $html);
        $this->assertStringNotContainsString('stock:', $html);
    }

    public function test_pdf_layout_keeps_two_fixed_slots_per_a4_page(): void
    {
        foreach ([1, 2, 3, 4] as $count) {
            $labels = collect(range(1, $count))
                ->map(fn (int $number): array => $this->labelPayload(
                    sku: 'SKU'.$number,
                    lot: 'LOT'.$number,
                    units: 1000,
                    number: 'Pallet '.$number,
                ));

            $html = $this->renderLabelsHtml($labels);

            $this->assertSame((int) ceil($count / 2), substr_count($html, 'class="label-page'));
            $this->assertSame((int) ceil($count / 2) * 2, substr_count($html, 'class="label-slot'));
            $this->assertSame($count, substr_count($html, '<div class="label-content">'));
            $this->assertSame((int) ceil($count / 2), $this->renderLabelsPdfPageCount($labels));
        }
    }

    public function test_no_lot_labels_fit_two_per_a4_page(): void
    {
        $labels = collect([
            $this->labelPayload(sku: '149677', lot: 'SIN LOTE', units: 5000, number: 'Pallet 1 de 2'),
            $this->labelPayload(sku: '149677', lot: 'SIN LOTE', units: 5000, number: 'Pallet 2 de 2'),
        ]);

        $html = $this->renderLabelsHtml($labels);

        $this->assertSame(1, substr_count($html, 'class="label-page'));
        $this->assertSame(2, substr_count($html, 'class="label-content"'));
        $this->assertStringContainsString('149677', $html);
        $this->assertStringContainsString('SIN LOTE', $html);
        $this->assertStringContainsString('5.000', $html);
        $this->assertSame(1, $this->renderLabelsPdfPageCount($labels));
    }

    public function test_lot_labels_fit_two_per_a4_page_with_the_same_structure(): void
    {
        $labels = collect([
            $this->labelPayload(sku: '11', lot: 'LL6E704', units: 1000, number: 'Pallet 1 de 2'),
            $this->labelPayload(sku: '11', lot: 'LL6E704', units: 1000, number: 'Pallet 2 de 2'),
        ]);

        $html = $this->renderLabelsHtml($labels);

        $this->assertSame(1, substr_count($html, 'class="label-page'));
        $this->assertSame(2, substr_count($html, 'class="label-content"'));
        $this->assertSame(2, substr_count($html, 'LL6E704'));
        $this->assertSame(1, $this->renderLabelsPdfPageCount($labels));
    }

    public function test_mixed_lot_and_no_lot_labels_share_one_a4_page(): void
    {
        $labels = collect([
            $this->labelPayload(sku: '149677', lot: 'SIN LOTE', units: 5000, number: 'Pallet 1'),
            $this->labelPayload(sku: '11', lot: 'LL6E704', units: 1000, number: 'Pallet 2'),
        ]);

        $html = $this->renderLabelsHtml($labels);

        $this->assertSame(1, substr_count($html, 'class="label-page'));
        $this->assertSame(2, substr_count($html, 'class="label-content"'));
        $this->assertStringContainsString('SIN LOTE', $html);
        $this->assertStringContainsString('LL6E704', $html);
        $this->assertSame(1, $this->renderLabelsPdfPageCount($labels));
    }

    public function test_long_label_content_stays_inside_two_label_page(): void
    {
        $labels = collect([
            $this->labelPayload(
                sku: 'REFERENCIA-LARGA-149677-FRIESLAND-SIN-LOTE',
                lot: 'SIN LOTE',
                units: 123456789,
                number: 'Pallet 1',
            ),
            $this->labelPayload(
                sku: 'REFERENCIA-LARGA-LL6E704-CON-LOTE-SEGUNDA-ETIQUETA',
                lot: 'LL6E704-LOTE-LARGO-CONTROLADO',
                units: 987654321,
                number: 'Pallet 2',
            ),
        ]);

        $html = $this->renderLabelsHtml($labels);

        $this->assertSame(1, substr_count($html, 'class="label-page'));
        $this->assertSame(2, substr_count($html, 'class="article-value article-value-wrap"'));
        $this->assertSame(1, $this->renderLabelsPdfPageCount($labels));
    }

    public function test_stock_pallet_label_rules_cover_pallets_and_peak(): void
    {
        [, , $stockPallet] = $this->labelFixtures(stockFullPallets: 6, stockPeak: 700);

        $labels = app(MerchandiseLabelService::class)->forStockPallet($stockPallet);

        $this->assertCount(7, $labels);
        $this->assertSame(6, $labels->where('type', 'PALLET')->count());
        $this->assertSame(1, $labels->where('type', 'PICO')->count());
        $this->assertSame(1600, $labels->first()['units']);
        $this->assertSame(700, $labels->last()['units']);
    }

    public function test_stock_with_only_full_pallets_generates_only_pallet_labels(): void
    {
        [, , $stockPallet] = $this->labelFixtures(stockFullPallets: 6, stockPeak: 0);

        $labels = app(MerchandiseLabelService::class)->forStockPallet($stockPallet);

        $this->assertCount(6, $labels);
        $this->assertSame(6, $labels->where('type', 'PALLET')->count());
        $this->assertSame(0, $labels->where('type', 'PICO')->count());
    }

    public function test_stock_without_enough_units_returns_controlled_error(): void
    {
        [, , $stockPallet] = $this->labelFixtures(stockFullPallets: 1, stockPeak: 0);

        DB::table('stock_pallets')->where('id', $stockPallet->id)->update([
            'units_per_pallet' => 0,
            'full_pallets' => 1,
            'quantity_units' => 0,
            'peak_1' => 0,
            'peaks_count' => 0,
        ]);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('labels.stock-pallet', $stockPallet->id))
            ->assertSessionHasErrors('labels');
    }

    public function test_generating_stock_label_does_not_modify_stock(): void
    {
        [, , $stockPallet] = $this->labelFixtures(stockFullPallets: 6, stockPeak: 700);
        $before = StockPallet::query()->findOrFail($stockPallet->id)->only([
            'quantity_units',
            'units_per_pallet',
            'full_pallets',
            'peaks_count',
            'warehouse_pallets',
            'location_id',
            'location_text',
            'status',
        ]);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('labels.stock-pallet', $stockPallet))
            ->assertOk();

        $after = StockPallet::query()->findOrFail($stockPallet->id)->only(array_keys($before));
        $this->assertSame($before, $after);
    }

    public function test_label_buttons_are_visible_for_internal_users_and_hidden_for_clients(): void
    {
        [$receipt, , $stockPallet, $client] = $this->labelFixtures();

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('goods-receipts.show', $receipt))
            ->assertOk()
            ->assertSee('Generar etiquetas')
            ->assertSee('Sacar etiqueta');

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('stock.index', ['client_id' => $client->id, 'item_id' => $stockPallet->item_id]))
            ->assertOk()
            ->assertSee('Sacar etiqueta');

        $this->actingAs($this->makeUserWithRole(Role::CLIENTE, $client))
            ->get(route('stock.index'))
            ->assertOk()
            ->assertDontSee('Sacar etiqueta');
    }

    public function test_line_from_another_receipt_is_not_accepted(): void
    {
        [$receipt] = $this->labelFixtures();
        [$otherReceipt, $otherLine] = $this->labelFixtures(receiptNumber: 'GR-OTHER');

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('labels.goods-receipt-line', [$receipt, $otherLine]))
            ->assertNotFound();

        $this->assertNotSame($receipt->id, $otherReceipt->id);
    }

    /**
     * @return array{0: GoodsReceipt, 1: GoodsReceiptLine, 2: StockPallet, 3: Client}
     */
    private function labelFixtures(
        string $receiptNumber = 'GR-000123',
        int $stockFullPallets = 10,
        int $stockPeak = 1460,
    ): array {
        $this->seed(RoleSeeder::class);
        $clientCode = $receiptNumber === 'GR-000123' ? 'FRIESLAND' : 'FRIESLAND_'.preg_replace('/[^A-Z0-9]+/', '_', strtoupper($receiptNumber));

        $client = Client::factory()->create([
            'name' => $clientCode,
            'code' => $clientCode,
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'FILM0419',
            'description' => 'Film retractil Friesland',
            'units_per_pallet' => 1600,
        ]);
        $warehouse = Warehouse::factory()->create([
            'client_id' => $client->id,
            'code' => 'WH-FR',
            'name' => 'FRIESLAND NAVE',
        ]);
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'A1',
            'name' => null,
        ]);
        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'receipt_number' => $receiptNumber,
            'received_at' => '2026-07-22',
            'status' => GoodsReceipt::STATUS_CONFIRMED,
            'stock_applied_at' => now(),
        ]);
        $line = GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => '471543',
            'quantity_units' => 17460,
            'units_per_pallet' => 1600,
            'pallet_count' => 10,
            'pico_units' => 1460,
            'peak_1' => 1460,
            'location_id' => $location->id,
        ]);
        $stockPallet = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'goods_receipt_id' => $receipt->id,
            'location_id' => $location->id,
            'lot' => '471543',
            'quantity_units' => ($stockFullPallets * 1600) + $stockPeak,
            'units_per_pallet' => 1600,
            'full_pallets' => $stockFullPallets,
            'peaks_count' => $stockPeak > 0 ? 1 : 0,
            'peak_1' => $stockPeak,
            'warehouse_pallets' => null,
            'received_at' => '2026-07-22',
        ]);

        return [$receipt->fresh(['client', 'lines.item', 'lines.location']), $line, $stockPallet, $client];
    }

    private function makeUserWithRole(string $roleSlug, ?Client $client = null): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $client?->id,
            'active' => true,
        ]);
    }

    private function renderLabelsHtml($labels): string
    {
        return view('labels.pdf', [
            'labels' => collect($labels),
            'origin' => 'Test labels',
            'generatedAt' => now(),
        ])->render();
    }

    private function renderLabelsPdfPageCount($labels): int
    {
        $output = Pdf::loadView('labels.pdf', [
            'labels' => collect($labels),
            'origin' => 'Test labels',
            'generatedAt' => now(),
        ])->setPaper('a4')->output();

        preg_match_all('/\/Type\s*\/Page(?!s)\b/', $output, $matches);

        return count($matches[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function labelPayload(
        string $sku,
        string $lot,
        int $units,
        string $number,
    ): array {
        return [
            'client_name' => 'FRIESLAND',
            'client_code' => 'FRIESLAND',
            'sku' => $sku,
            'description' => 'Producto de prueba',
            'article' => $sku.' - Producto de prueba',
            'lot' => $lot,
            'units' => $units,
            'type' => 'PALLET',
            'number' => $number,
            'receipt_number' => 'GR-TEST',
            'received_at' => '24/07/2026',
            'location' => 'A1',
            'traceability' => 'test:'.$sku.':'.$number,
        ];
    }
}
