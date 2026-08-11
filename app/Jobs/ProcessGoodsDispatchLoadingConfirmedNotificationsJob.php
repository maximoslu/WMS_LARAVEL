<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesNotificationDelivery;
use App\Models\GoodsDispatch;
use App\Models\User;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessGoodsDispatchLoadingConfirmedNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RetriesNotificationDelivery;
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
            $this->handleDeliveryException($exception);
        }
    }
}
