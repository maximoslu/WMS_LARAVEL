<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    public const SUPERADMIN = 'superadmin';
    public const ADMINISTRACION = 'administracion';
    public const ALMACEN = 'almacen';
    public const CLIENTE = 'cliente';

    protected $fillable = [
        'name',
        'slug',
        'level',
    ];

    /**
     * @return array<int, array{name: string, slug: string, level: int}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'Cliente', 'slug' => self::CLIENTE, 'level' => 10],
            ['name' => 'Almacen', 'slug' => self::ALMACEN, 'level' => 20],
            ['name' => 'Administracion', 'slug' => self::ADMINISTRACION, 'level' => 30],
            ['name' => 'Superadmin', 'slug' => self::SUPERADMIN, 'level' => 40],
        ];
    }

    public static function slugExists(string $slug): bool
    {
        return collect(self::defaults())->contains(fn (array $role) => $role['slug'] === $slug);
    }

    public static function defaultLevelFor(string $slug): ?int
    {
        return collect(self::defaults())
            ->firstWhere('slug', $slug)['level'] ?? null;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
