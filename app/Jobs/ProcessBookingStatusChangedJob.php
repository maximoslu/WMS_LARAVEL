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

class ProcessBookingStatusChangedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $bookingId,
        public readonly string $previousStatus,
    ) {}

    public function handle(BookingNotificationService $notificationService): void
    {
        $booking = Booking::query()
            ->with(['client.users.role', 'requestedBy'])
            ->find($this->bookingId);

        if ($booking === null) {
            return;
        }

        try {
            $notificationService->deliverStatusChangedNotifications($booking, $this->previousStatus);
        } catch (Throwable $exception) {
            Log::warning('Fallo al procesar notificaciones de cambio de estado de booking.', [
                'booking_id' => $this->bookingId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
