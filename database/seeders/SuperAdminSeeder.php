<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::query()->where('slug', Role::SUPERADMIN)->firstOrFail();

        $user = User::query()->firstOrNew([
            'email' => 'administracion@maximosl.com',
        ]);

        if (! $user->exists) {
            $user->name = 'Administracion MAXIMO';
            $user->password = Hash::make(Str::password(32));
        }

        $user->role()->associate($role);
        $user->email_verified_at ??= Carbon::now();
        $user->save();
    }
}
