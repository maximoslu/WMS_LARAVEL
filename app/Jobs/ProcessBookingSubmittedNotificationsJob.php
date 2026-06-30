<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\Bookings\BookingNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessBookingSubmittedNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
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
            Log::warning('Fallo al procesar notificaciones de nuevo booking.', [
                'booking_id' => $this->bookingId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
