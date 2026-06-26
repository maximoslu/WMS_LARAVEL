<?php

namespace App\Support;

use App\Models\GoodsDispatch;
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
            MerchandiseRequest::STATUS_PREPARING => 'En preparacion',
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
            GoodsDispatch::STATUS_PREPARING => 'En preparacion',
            GoodsDispatch::STATUS_SENT => 'Enviado',
            GoodsDispatch::STATUS_COMPLETED => 'Completado',
            GoodsDispatch::STATUS_CANCELLED => 'Cancelado',
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
}
