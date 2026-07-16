<?php

namespace App\Jobs;

use App\Models\StockAlertEvent;
use App\Services\Audit\AuditLogService;
use App\Services\BrevoMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class SendStockAlertEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $stockAlertEventId) {}

    public function handle(BrevoMailService $mail, AuditLogService $audit): void
    {
        $event = StockAlertEvent::query()->with(['client', 'item', 'rule'])->find($this->stockAlertEventId);

        if (! $event instanceof StockAlertEvent
            || $event->resolved_at !== null
            || in_array($event->notification_status, ['sent', 'skipped'], true)) {
            return;
        }

        try {
            $mail->sendStockAlert($event, $event->recipients ?? []);
            $event->forceFill([
                'notification_status' => 'sent',
                'notification_error' => null,
                'notified_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $event->forceFill([
                'notification_status' => 'failed',
                'notification_error' => Str::limit($exception->getMessage(), 2000, '...'),
            ])->save();
            $this->recordAuditSafely(
                $audit,
                $event,
                'stock_alert_send_failed',
                'El envio del aviso de stock ha fallado y quedara disponible para reintento.',
                'warning',
                ['exception' => $exception::class],
            );

            throw $exception;
        }

        $this->recordAuditSafely(
            $audit,
            $event,
            'stock_alert_sent',
            'Aviso de stock enviado a los destinatarios configurados.',
            'info',
            ['recipient_count' => count($event->recipients ?? [])],
        );
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function uniqueId(): string
    {
        return 'stock-alert-email:'.$this->stockAlertEventId;
    }

    public int $uniqueFor = 600;

    /** @param array<string, mixed> $metadata */
    private function recordAuditSafely(
        AuditLogService $audit,
        StockAlertEvent $event,
        string $auditEvent,
        string $description,
        string $severity,
        array $metadata,
    ): void {
        try {
            $audit->record(
                event: $auditEvent,
                module: 'stock_alerts',
                description: $description,
                auditable: $event,
                subject: $event->item,
                clientId: $event->client_id,
                newValues: ['notification_status' => $event->notification_status, 'notified_at' => $event->notified_at],
                metadata: $metadata,
                source: 'queue',
                severity: $severity,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
