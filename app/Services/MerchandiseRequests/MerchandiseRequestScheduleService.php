<?php

namespace App\Services\MerchandiseRequests;

use Carbon\CarbonInterface;

class MerchandiseRequestScheduleService
{
    public function isWithinContractualWindow(?CarbonInterface $submittedAt = null): bool
    {
        $schedule = (array) config('wms.merchandise_requests.schedule', []);
        $timezone = (string) ($schedule['timezone'] ?? config('app.timezone'));
        $businessDays = array_map('intval', $schedule['business_days'] ?? [1, 2, 3, 4, 5]);
        $start = (string) ($schedule['start'] ?? '07:00');
        $end = (string) ($schedule['end'] ?? '15:00');
        $current = ($submittedAt ?? now())->copy()->timezone($timezone);

        $currentMinutes = ((int) $current->format('H') * 60) + (int) $current->format('i');
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));
        $startMinutes = ($startHour * 60) + $startMinute;
        $endMinutes = ($endHour * 60) + $endMinute;

        return in_array($current->isoWeekday(), $businessDays, true)
            && $currentMinutes >= $startMinutes
            && $currentMinutes <= $endMinutes;
    }

    public function isOutsideContractualWindow(?CarbonInterface $submittedAt = null): bool
    {
        return ! $this->isWithinContractualWindow($submittedAt);
    }

    public function preSubmissionWarning(?CarbonInterface $submittedAt = null): ?string
    {
        if (! $this->isOutsideContractualWindow($submittedAt)) {
            return null;
        }

        return 'Estas realizando el pedido fuera de la ventana operativa contractual de planificacion. '
            .'Los pedidos para servicio al dia siguiente deben registrarse de lunes a viernes entre las 07:00 y las 15:00. '
            .'Si continuas, lo gestionaremos con la mayor diligencia posible, aunque no podemos garantizar disponibilidad operativa para el siguiente dia habil.';
    }

    public function postSubmissionWarning(?CarbonInterface $submittedAt = null): ?string
    {
        if (! $this->isOutsideContractualWindow($submittedAt)) {
            return null;
        }

        return 'Pedido registrado fuera de la ventana operativa contractual. '
            .'Lo tramitaremos con la mayor diligencia posible, pero no podemos garantizar su preparacion o expedicion para el siguiente dia habil al haberse recibido fuera del horario de planificacion logistica establecido.';
    }
}
