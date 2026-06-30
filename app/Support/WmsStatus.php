<?php

namespace App\Support;

use App\Models\GoodsDispatch;
use App\Models\Booking;
use App\Models\MerchandiseRequest;

class WmsStatus
{
    /**
     * @return array<string, string>
     */
    public static function merchandiseRequestLabels(): array
    {
        return [
            MerchandiseRequest::STATUS_PENDING => 'Pendiente',
            MerchandiseRequest::STATUS_PREPARING => 'En preparación',
            MerchandiseRequest::STATUS_SENT => 'Enviado',
            MerchandiseRequest::STATUS_COMPLETED => 'Completado',
            MerchandiseRequest::STATUS_CANCELLED => 'Cancelado',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function goodsDispatchLabels(): array
    {
        return [
            GoodsDispatch::STATUS_DRAFT => 'Borrador',
            GoodsDispatch::STATUS_PREPARING => 'En preparación',
            GoodsDispatch::STATUS_SENT => 'Enviado',
            GoodsDispatch::STATUS_COMPLETED => 'Completado',
            GoodsDispatch::STATUS_CANCELLED => 'Cancelado',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function bookingLabels(): array
    {
        return [
            Booking::STATUS_REQUESTED => 'Solicitado',
            Booking::STATUS_APPROVED => 'Aprobado',
            Booking::STATUS_PLANNED => 'Planificado',
            Booking::STATUS_IN_PROGRESS => 'En curso',
            Booking::STATUS_COMPLETED => 'Completado',
            Booking::STATUS_CANCELLED => 'Cancelado',
            Booking::STATUS_REJECTED => 'Rechazado',
        ];
    }

    public static function merchandiseRequestLabel(string $status): string
    {
        return self::merchandiseRequestLabels()[$status] ?? ucfirst($status);
    }

    public static function goodsDispatchLabel(string $status): string
    {
        return self::goodsDispatchLabels()[$status] ?? ucfirst($status);
    }

    public static function bookingLabel(string $status): string
    {
        return self::bookingLabels()[$status] ?? ucfirst($status);
    }
}
