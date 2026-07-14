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
        $haystack = self::haystack($notification);

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

    public static function dashboardModuleKey(DatabaseNotification $notification): ?string
    {
        $haystack = self::haystack($notification);

        return match (true) {
            self::containsAny($haystack, ['solicitud_mercancia', 'pedido', 'solicitud-mercancia']) => 'solicitudes',
            self::containsAny($haystack, ['solicitud_acceso', 'solicitudes-acceso', 'access_request', 'usuario']) => 'usuarios',
            self::containsAny($haystack, ['stock', 'inventario', 'importacion', 'importación']) => 'stock',
            self::containsAny($haystack, ['booking', 'calendario']) => 'bookings',
            default => null,
        };
    }

    private static function haystack(DatabaseNotification $notification): string
    {
        $data = $notification->data ?? [];

        return Str::lower(implode(' ', array_filter([
            $notification->type,
            $data['type'] ?? null,
            $data['title'] ?? null,
            $data['body'] ?? null,
            $data['url'] ?? null,
        ])));
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
