<?php

namespace App\Services\Audit;

use App\Models\StockImport;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuditCleanupService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{type:string,count:int,warnings:list<string>,description:string}
     */
    public function preview(array $filters): array
    {
        $type = (string) $filters['cleanup_type'];

        return match ($type) {
            'notifications' => $this->previewNotifications($filters),
            'stock_imports' => $this->previewStockImports($filters),
            'failed_jobs' => $this->previewFailedJobs($filters),
            default => [
                'type' => $type,
                'count' => 0,
                'warnings' => ['Tipo de limpieza no soportado en esta fase.'],
                'description' => 'Sin accion disponible.',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{deleted:int, preview:array{type:string,count:int,warnings:list<string>,description:string}}
     */
    public function execute(array $filters, int $actorId): array
    {
        $preview = $this->preview($filters);

        if ($preview['count'] === 0) {
            return [
                'deleted' => 0,
                'preview' => $preview,
            ];
        }

        $deleted = $this->db->transaction(function () use ($filters): int {
            return match ((string) $filters['cleanup_type']) {
                'notifications' => $this->notificationsQuery($filters)->delete(),
                'stock_imports' => $this->stockImportsQuery($filters)->delete(),
                'failed_jobs' => Schema::hasTable('failed_jobs')
                    ? $this->failedJobsQuery($filters)->delete()
                    : 0,
                default => 0,
            };
        });

        Log::info('Audit cleanup executed', [
            'actor_id' => $actorId,
            'cleanup_type' => $filters['cleanup_type'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'client_id' => $filters['client_id'] ?? null,
            'status' => $filters['status'] ?? null,
            'deleted' => $deleted,
        ]);

        return [
            'deleted' => $deleted,
            'preview' => $preview,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function previewNotifications(array $filters): array
    {
        return [
            'type' => 'notifications',
            'count' => $this->notificationsQuery($filters)->count(),
            'warnings' => [
                'Se eliminan notificaciones antiguas del panel para reducir volumen historico.',
                'No afecta a usuarios, clientes, stock ni documentos.',
            ],
            'description' => 'Notificaciones antiguas entre fechas.',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function previewStockImports(array $filters): array
    {
        return [
            'type' => 'stock_imports',
            'count' => $this->stockImportsQuery($filters)->count(),
            'warnings' => [
                'Solo se limpian importaciones no operativas: fallidas o previsualizadas.',
                'No se borran partidas de stock activas generadas por importaciones confirmadas.',
            ],
            'description' => 'Importaciones antiguas fallidas o pendientes de confirmar.',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function previewFailedJobs(array $filters): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [
                'type' => 'failed_jobs',
                'count' => 0,
                'warnings' => ['La tabla failed_jobs no existe en este entorno.'],
                'description' => 'No disponible.',
            ];
        }

        return [
            'type' => 'failed_jobs',
            'count' => $this->failedJobsQuery($filters)->count(),
            'warnings' => [
                'Se eliminan solo jobs fallidos historicos.',
                'No se tocan colas activas ni jobs pendientes.',
            ],
            'description' => 'Jobs fallidos entre fechas.',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function notificationsQuery(array $filters)
    {
        return DB::table('notifications')
            ->whereDate('created_at', '>=', (string) $filters['date_from'])
            ->whereDate('created_at', '<=', (string) $filters['date_to']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function stockImportsQuery(array $filters)
    {
        $status = $filters['status'] ?? null;

        return StockImport::query()
            ->whereDate('created_at', '>=', (string) $filters['date_from'])
            ->whereDate('created_at', '<=', (string) $filters['date_to'])
            ->when(($filters['client_id'] ?? null) !== null, fn ($query) => $query->where('client_id', (int) $filters['client_id']))
            ->whereIn('status', [
                StockImport::STATUS_FAILED,
                StockImport::STATUS_PENDING_CONFIRMATION,
                StockImport::STATUS_PREVIEWED,
            ])
            ->when(filled($status), fn ($query) => $query->where('status', $status));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function failedJobsQuery(array $filters)
    {
        return DB::table('failed_jobs')
            ->whereDate('failed_at', '>=', (string) $filters['date_from'])
            ->whereDate('failed_at', '<=', (string) $filters['date_to']);
    }
}
