<?php

namespace App\Services\MerchandiseRequests;

use Carbon\CarbonInterface;

class MerchandiseRequestScheduleService
{
    public function submissionNotice(?CarbonInterface $submittedAt = null): string
    {
        $schedule = (array) config('wms.merchandise_requests.schedule', []);
        $timezone = (string) ($schedule['timezone'] ?? config('app.timezone'));
        $businessDays = array_map('intval', $schedule['business_days'] ?? [1, 2, 3, 4, 5]);
        $start = (string) ($schedule['start'] ?? '08:00');
        $end = (string) ($schedule['end'] ?? '17:00');
        $current = ($submittedAt ?? now())->copy()->timezone($timezone);

        $currentMinutes = ((int) $current->format('H') * 60) + (int) $current->format('i');
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));
        $startMinutes = ($startHour * 60) + $startMinute;
        $endMinutes = ($endHour * 60) + $endMinute;

        $isBusinessDay = in_array($current->isoWeekday(), $businessDays, true);
        $isWithinWindow = $currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes;

        if ($isBusinessDay && $isWithinWindow) {
            return 'Pedido recibido correctamente. Lo revisaremos dentro del horario operativo previsto.';
        }

        return 'Pedido recibido correctamente. Ha quedado registrado fuera del horario operativo provisional y se revisara en la siguiente ventana laboral.';
    }
}
