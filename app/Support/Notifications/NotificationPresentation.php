<?php

namespace App\Support\Notifications;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class NotificationPresentation
{
    /**
     * @return array{key:string,label:string}
     */
    public static function for(DatabaseNotification $notification): array
    {
        $data = $notification->data ?? [];
        $haystack = Str::lower(implode(' ', array_filter([
            $notification->type,
            $data['type'] ?? null,
            $data['title'] ?? null,
            $data['body'] ?? null,
            $data['url'] ?? null,
        ])));

        if (self::containsAny($haystack, ['error', 'fallo', 'incidencia'])) {
            return ['key' => 'error', 'label' => 'ERROR'];
        }

        if (self::containsAny($haystack, ['booking'])) {
            return ['key' => 'booking', 'label' => 'BOOKING'];
        }

        if (self::containsAny($haystack, ['albaran_salida', 'confirmacion_carga_real', 'dispatch', 'salida', 'expedicion'])) {
            return ['key' => 'salida', 'label' => 'SALIDA'];
        }

        if (self::containsAny($haystack, ['solicitud_mercancia', 'pedido', 'solicitud'])) {
            return ['key' => 'pedido', 'label' => 'PEDIDO'];
        }

        if (self::containsAny($haystack, ['stock', 'inventario'])) {
            return ['key' => 'stock', 'label' => 'STOCK'];
        }

        return ['key' => 'sistema', 'label' => 'SISTEMA'];
    }

    /**
     * @param  list<string>  $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
