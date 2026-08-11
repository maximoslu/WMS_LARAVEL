<?php

namespace App\Console\Commands;

use App\Models\StockImport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PruneStockImportTemporaryFilesCommand extends Command
{
    protected $signature = 'wms:stock-imports:prune-temp
        {--hours= : Antiguedad maxima de previews pendientes}
        {--apply : Elimina los temporales; sin esta opcion solo muestra el plan}';

    protected $description = 'Limpia temporales de importaciones finalizadas y previews caducadas.';

    public function handle(): int
    {
        $hours = max(1, (int) ($this->option('hours') ?: config('wms.stock_imports.temporary_retention_hours', 24)));
        $cutoff = Carbon::now()->subHours($hours);
        $apply = (bool) $this->option('apply');
        $imports = StockImport::query()
            ->where(function ($query) use ($cutoff): void {
                $query->whereIn('status', [
                    StockImport::STATUS_IMPORTED,
                    StockImport::STATUS_FAILED,
                    StockImport::STATUS_STALE,
                ])->orWhere(function ($pendingQuery) use ($cutoff): void {
                    $pendingQuery
                        ->whereIn('status', [StockImport::STATUS_PENDING_CONFIRMATION, StockImport::STATUS_PREVIEWED])
                        ->where('created_at', '<=', $cutoff);
                });
            })
            ->orderBy('id')
            ->get();

        $matched = 0;
        $deleted = 0;
        $expired = 0;

        foreach ($imports as $stockImport) {
            $path = $this->safePath((string) $stockImport->stored_path);

            if ($path === null) {
                $this->warn('Ruta no segura omitida para importacion #'.$stockImport->id.'.');

                continue;
            }

            $matched++;
            $exists = Storage::disk('local')->exists($path);
            $isExpiredPreview = in_array($stockImport->status, [StockImport::STATUS_PENDING_CONFIRMATION, StockImport::STATUS_PREVIEWED], true)
                && $stockImport->created_at?->lte($cutoff);
            $this->line(sprintf(
                ' - #%d / %s / %s / %s',
                $stockImport->id,
                $stockImport->status,
                $exists ? 'archivo presente' : 'archivo ausente',
                $apply ? 'apply' : 'dry-run',
            ));

            if (! $apply) {
                continue;
            }

            if (! $exists || Storage::disk('local')->delete($path)) {
                $deleted += $exists ? 1 : 0;

                if ($isExpiredPreview) {
                    $stockImport->forceFill([
                        'status' => StockImport::STATUS_FAILED,
                        'errors_json' => ['La previsualizacion caduco y su fichero temporal fue eliminado. Vuelve a subir el Excel.'],
                    ])->save();
                    $expired++;
                }
            }
        }

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' wms:stock-imports:prune-temp');
        $this->line('Importaciones revisadas: '.$matched);
        $this->line('Archivos eliminados: '.$deleted);
        $this->line('Previews caducadas: '.$expired);

        return self::SUCCESS;
    }

    private function safePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        return str_starts_with($path, 'stock-imports/') && ! str_contains($path, '..')
            ? $path
            : null;
    }
}
