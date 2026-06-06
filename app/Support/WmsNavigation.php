<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

class WmsNavigation
{
    /**
     * @return array<int, array<string, string>>
     */
    public static function forUser(User $user): array
    {
        return collect(config('wms.modules', []))
            ->filter(fn (array $module) => $user->canAccessRole($module['minimum_role']))
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>|null
     */
    public static function module(string $key): ?array
    {
        $module = collect(config('wms.modules', []))
            ->firstWhere('key', $key);

        if ($module === null) {
            return null;
        }

        $module['minimum_role_name'] = self::roleName($module['minimum_role']);

        return $module;
    }

    public static function roleName(string $slug): string
    {
        return collect(Role::defaults())
            ->firstWhere('slug', $slug)['name'] ?? ucfirst($slug);
    }
}
