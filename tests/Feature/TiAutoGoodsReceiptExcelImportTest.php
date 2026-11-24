<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class TiAutoGoodsReceiptExcelImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_user_can_create_a_ti_auto_draft_from_the_carrier_workbook(): void
    {
        $this->seed(RoleSeeder::class);
        $tiAuto = Client::factory()->create(['name' => 'TI Automotive', 'code' => 'TIAUTO']);
        $user = $this->userWithRole(Role::ALMACEN);

        $response = $this->actingAs($user)->post(route('goods-receipts.ti-auto-excel.store'), [
            'excel' => $this->workbook([
                ['Part Number', 'Customer Part Number', 'Description', 'Delivery note', 'Lot Number', 'Means of Transport', 20261122, 'E5T', 'E54', 'F/T'],
                ['PMBE0002ACF33', 'PMBE0002ACF73', 'PMBE 0002 ACF 73', '00502842', 'TR0001', 'R-8046-BDG', 27, 27, 0, true],
                ['PMBE0002ACF33', 'PMBE0002ACF73', 'PMBE 0002 ACF 73', '00502842', 'TR0002', 'R-8046-BDG', 27, 27, 0, true],
                ['PMBE0004ABF33', 'PMBE0004ABF73', 'PMBE 0004 ABF 73', '00502843', 'TR0003', 'R-8046-BDG', 75, 75, 0, true],
                ['PMBE0004ABF33', 'PMBE0004ABF73', 'PMBE 0004 ABF 73', '00502843', 'TR0004', 'R-8046-BDG', 75, 75, 0, true],
                ['PMMA0037AAF74', 'PMMA0037AAF74', 'PMMA 0037 AAF 74', '00502844', 'TR0005', 'R-8046-BDG', 50, 50, 0, true],
                ['PMMA0037AAF74', 'PMMA0037AAF74', 'PMMA 0037 AAF 74', '00502844', 'TR0006', 'R-8046-BDG', 19, 19, 0, true],
            ]),
        ]);

        $receipt = GoodsReceipt::query()->sole();

        $response->assertRedirect(route('goods-receipts.show', $receipt));
        $this->assertSame($tiAuto->id, $receipt->client_id);
        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->status);
        $this->assertSame('2026-11-22', $receipt->received_at?->toDateString());
        $this->assertSame('00502842 / 00502843 / 00502844', $receipt->external_document_number);
        $this->assertDatabaseCount('goods_receipt_lines', 4);
        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'sku' => 'PMBE0002ACF73',
            'units_per_pallet' => 27,
            'pallet_count' => 2,
            'quantity_units' => 54,
            'pico_units' => null,
        ]);
        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'sku' => 'PMBE0004ABF73',
            'units_per_pallet' => 75,
            'pallet_count' => 2,
            'quantity_units' => 150,
        ]);
        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'sku' => 'PMMA0037AAF74',
            'units_per_pallet' => 50,
            'pallet_count' => 1,
            'quantity_units' => 50,
        ]);
        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'sku' => 'PMMA0037AAF74',
            'units_per_pallet' => 19,
            'pallet_count' => 1,
            'quantity_units' => 19,
        ]);
        $this->assertDatabaseCount('stock_pallets', 0);

        $this->actingAs($user)
            ->get(route('goods-receipts.show', $receipt))
            ->assertOk()
            ->assertDontSee('Pico 1');
    }

    public function test_client_cannot_access_ti_auto_excel_receipt_import(): void
    {
        $this->seed(RoleSeeder::class);
        $client = Client::factory()->create();
        $user = $this->userWithRole(Role::CLIENTE, $client);

        $this->actingAs($user)
            ->get(route('goods-receipts.ti-auto-excel.create'))
            ->assertForbidden();
    }

    public function test_import_requires_a_recognized_ti_auto_workbook(): void
    {
        $this->seed(RoleSeeder::class);
        Client::factory()->create(['code' => 'TIAUTO']);
        $user = $this->userWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->from(route('goods-receipts.ti-auto-excel.create'))
            ->post(route('goods-receipts.ti-auto-excel.store'), [
                'excel' => $this->workbook([['Referencia', 'Cantidad'], ['ABC', 20]]),
            ])
            ->assertRedirect(route('goods-receipts.ti-auto-excel.create'))
            ->assertSessionHasErrors('excel');

        $this->assertDatabaseCount('goods_receipts', 0);
    }

    private function userWithRole(string $roleSlug, ?Client $client = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'client_id' => $roleSlug === Role::CLIENTE ? $client?->id : null,
        ]);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function workbook(array $rows): UploadedFile
    {
        $temporary = tempnam(sys_get_temp_dir(), 'ti-auto-import-');
        $path = $temporary.'.xlsx';
        if ($temporary !== false && file_exists($temporary)) {
            unlink($temporary);
        }

        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return UploadedFile::fake()->createWithContent('5 CAMION MAXIMO.xlsx', file_get_contents($path) ?: '');
    }
}
