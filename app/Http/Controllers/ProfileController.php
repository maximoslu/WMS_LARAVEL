<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Services\Audit\AuditLogService;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function update(UpdateProfileRequest $request, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];

        if ($validated['email'] !== $user->email) {
            $payload['email_verified_at'] = null;
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path !== null) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $payload['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        $oldValues = $user->only(['name', 'email', 'avatar_path']);
        $passwordChanged = ! empty($validated['password']);

        DB::transaction(function () use ($user, $payload, $oldValues, $passwordChanged, $request, $audit): void {
            $user->update($payload);
            $audit->record(
                event: 'profile_updated',
                module: 'users',
                description: 'Perfil de usuario actualizado.',
                auditable: $user,
                user: $user,
                clientId: $user->client_id,
                oldValues: $oldValues,
                newValues: $user->fresh()->only(['name', 'email', 'avatar_path']),
                request: $request,
            );

            if ($passwordChanged) {
                $audit->record(
                    event: 'password_changed',
                    module: 'users',
                    description: 'Contrasena actualizada por el propio usuario.',
                    auditable: $user,
                    user: $user,
                    clientId: $user->client_id,
                    request: $request,
                    severity: 'important',
                );
            }
        });

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Perfil actualizado correctamente.');
    }

    public function destroyAvatar(Request $request, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
            $oldAvatar = $user->avatar_path;

            DB::transaction(function () use ($user, $oldAvatar, $request, $audit): void {
                $user->update(['avatar_path' => null]);
                $audit->record(
                    event: 'profile_avatar_removed',
                    module: 'users',
                    description: 'Avatar de usuario eliminado.',
                    auditable: $user,
                    user: $user,
                    clientId: $user->client_id,
                    oldValues: ['avatar_path' => $oldAvatar],
                    newValues: ['avatar_path' => null],
                    request: $request,
                );
            });
        }

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Avatar eliminado correctamente.');
    }
}
