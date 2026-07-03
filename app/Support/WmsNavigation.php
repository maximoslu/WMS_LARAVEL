<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

class WmsNavigation
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sectionsForUser(User $user): array
    {
        return collect(config('wms.navigation_sections', []))
            ->map(function (array $section) use ($user): ?array {
                $children = collect($section['children'] ?? [])
                    ->filter(fn (array $child) => $user->canAccessRole($child['minimum_role']))
                    ->map(fn (array $child): array => self::decorateChild($child, $user))
                    ->values()
                    ->all();

                if ($children === []) {
                    return null;
                }

                return [
                    ...$section,
                    'minimum_role_name' => self::roleName($section['minimum_role']),
                    'children' => $children,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function module(string $key): ?array
    {
        $section = collect(config('wms.navigation_sections', []))
            ->first(function (array $section) use ($key): bool {
                return collect($section['children'] ?? [])->contains(
                    fn (array $child) => $child['key'] === $key
                );
            });

        if ($section === null) {
            return null;
        }

        $module = collect($section['children'] ?? [])
            ->firstWhere('key', $key);

        if ($module === null) {
            return null;
        }

        return [
            ...self::decorateChild($module),
            'minimum_role_name' => self::roleName($module['minimum_role']),
            'section_key' => $section['key'],
            'section_title' => $section['title'],
            'section_summary' => $section['summary'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function allVisibleModulesForUser(User $user): array
    {
        return collect(self::sectionsForUser($user))
            ->flatMap(fn (array $section) => $section['children'])
            ->values()
            ->all();
    }

    public static function roleName(string $slug): string
    {
        return collect(Role::defaults())
            ->firstWhere('slug', $slug)['name'] ?? ucfirst($slug);
    }

    /**
     * @param  array<string, mixed>  $child
     * @return array<string, mixed>
     */
    private static function decorateChild(array $child, ?User $user = null): array
    {
        $status = $child['status'] ?? 'ready';
        $displayTitle = $child['title'];
        $displayRoute = $child['route'];
        $displayIcon = null;

        if ($user?->hasRole(Role::CLIENTE)) {
            match ($child['key'] ?? null) {
                'stock' => [$displayTitle, $displayRoute, $displayIcon] = ['STOCK', 'stock.index', 'stock'],
                'bookings' => [$displayTitle, $displayRoute, $displayIcon] = ['BOOKING', 'bookings.index', 'booking'],
                'solicitudes' => [$displayTitle, $displayRoute, $displayIcon] = ['PEDIDOS', 'merchandise-requests.create', 'orders'],
                default => null,
            };
        }

        return [
            ...$child,
            'display_title' => $displayTitle,
            'display_route' => $displayRoute,
            'display_icon' => $displayIcon,
            'status' => $status,
            'status_label' => match ($status) {
                'ready' => 'Disponible',
                'placeholder' => 'Proximamente',
                default => ucfirst((string) $status),
            },
        ];
    }
}
