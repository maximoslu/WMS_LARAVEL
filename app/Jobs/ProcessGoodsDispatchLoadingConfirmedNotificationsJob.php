<?php

namespace App\Jobs;

use App\Models\GoodsDispatch;
use App\Models\User;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessGoodsDispatchLoadingConfirmedNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $goodsDispatchId,
        public readonly int $confirmedByUserId,
    ) {}

    public function handle(MerchandiseRequestNotificationService $notificationService): void
    {
        $dispatch = GoodsDispatch::query()
            ->with(['client', 'lines.item', 'merchandiseRequest'])
            ->find($this->goodsDispatchId);
        $confirmedBy = User::query()->find($this->confirmedByUserId);

        if ($dispatch === null || $confirmedBy === null) {
            return;
        }

        try {
            $notificationService->deliverLoadingConfirmedNotifications($dispatch, $confirmedBy);
        } catch (Throwable $exception) {
            Log::warning('Fallo al procesar notificaciones de confirmacion de carga.', [
                'dispatch_id' => $this->goodsDispatchId,
                'confirmed_by' => $this->confirmedByUserId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
