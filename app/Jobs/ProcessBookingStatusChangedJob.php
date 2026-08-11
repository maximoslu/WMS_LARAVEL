<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesNotificationDelivery;
use App\Models\Booking;
use App\Services\Bookings\BookingNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessBookingStatusChangedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RetriesNotificationDelivery;
    use SerializesModels;

    public function __construct(
        public readonly int $bookingId,
        public readonly string $previousStatus,
        public readonly ?string $currentStatus = null,
        public readonly ?string $eventVersion = null,
    ) {}

    public function handle(BookingNotificationService $notificationService): void
    {
        $booking = Booking::query()
            ->with(['client.users.role', 'requestedBy'])
            ->find($this->bookingId);

        if ($booking === null) {
            return;
        }

        if (isset($this->currentStatus) && $this->currentStatus !== null && $booking->status !== $this->currentStatus) {
            return;
        }

        try {
            $notificationService->deliverStatusChangedNotifications(
                $booking,
                $this->previousStatus,
                isset($this->eventVersion) ? $this->eventVersion : null,
            );
        } catch (Throwable $exception) {
            $this->handleDeliveryException($exception);
        }
    }
}
