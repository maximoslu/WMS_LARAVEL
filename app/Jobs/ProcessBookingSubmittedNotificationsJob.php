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

class ProcessBookingSubmittedNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RetriesNotificationDelivery;
    use SerializesModels;

    public function __construct(
        public readonly int $bookingId,
    ) {}

    public function handle(BookingNotificationService $notificationService): void
    {
        $booking = Booking::query()
            ->with(['client', 'requestedBy'])
            ->find($this->bookingId);

        if ($booking === null) {
            return;
        }

        try {
            $notificationService->deliverSubmittedNotifications($booking);
        } catch (Throwable $exception) {
            $this->handleDeliveryException($exception);
        }
    }
}
