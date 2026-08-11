<?php

namespace Tests\Benchmark;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\Stock\StockExcelImportService;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class StockImportPerformanceBenchmark extends TestCase
{
    use RefreshDatabase;

    public function test_parse_and_confirmation_reference(): void
    {
        $rows = max(1, (int) getenv('BENCH_ROWS'));

        if ($rows > (int) config('wms.stock_imports.max_rows')) {
            $this->fail('The benchmark row count must not exceed the configured application limit.');
        }

        $this->seed([RoleSeeder::class, ClientSeeder::class]);
        Storage::fake('local');
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $role = Role::query()->where('slug', Role::SUPERADMIN)->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id]);
        $path = tempnam(sys_get_temp_dir(), 'wms-confirm-bench-').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('STOCK');
        $writer->addRow(Row::fromValues(['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets']));

        for ($index = 1; $index <= $rows; $index++) {
            $writer->addRow(Row::fromValues([
                sprintf('CONF-%05d', $index),
                'Fila sintetica '.$index,
                'NO LOTE',
                1000,
                1000,
                1,
            ]));
        }

        $writer->close();
        $fileBytes = filesize($path) ?: 0;
        $file = UploadedFile::fake()->createWithContent('stock.xlsx', file_get_contents($path) ?: '');
        @unlink($path);
        $parseMemoryBefore = memory_get_usage(true);
        $parseStartedAt = hrtime(true);
        $stockImport = app(StockExcelImportService::class)->createPreview($client, $user, $file)['stock_import'];
        $parseElapsedMs = (hrtime(true) - $parseStartedAt) / 1_000_000;
        $parsePeakDelta = max(0, memory_get_peak_usage(true) - $parseMemoryBefore);
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $confirmationMemoryBefore = memory_get_usage(true);
        $confirmationStartedAt = hrtime(true);
        $result = app(StockExcelImportService::class)->confirm($stockImport, $user);
        $confirmationElapsedMs = (hrtime(true) - $confirmationStartedAt) / 1_000_000;
        $confirmationPeakDelta = max(0, memory_get_peak_usage(true) - $confirmationMemoryBefore);

        fwrite(STDOUT, json_encode([
            'rows' => $rows,
            'file_bytes' => $fileBytes,
            'parse_ms' => round($parseElapsedMs, 2),
            'parse_peak_delta_bytes' => $parsePeakDelta,
            'confirmation_ms' => round($confirmationElapsedMs, 2),
            'confirmation_peak_delta_bytes' => $confirmationPeakDelta,
            'confirmation_queries' => $queries,
        ], JSON_THROW_ON_ERROR).PHP_EOL);

        $this->assertSame($rows, $result['imported_rows']);
    }
}
