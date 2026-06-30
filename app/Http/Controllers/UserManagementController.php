<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateManagedUserRequest;
use App\Models\AccessRequest;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));
        $roleId = $request->integer('role_id');
        $clientId = $request->integer('client_id');
        $status = (string) $request->string('status', 'active');

        $users = User::query()
            ->with(['role', 'client'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($roleId > 0, fn ($query) => $query->where('role_id', $roleId))
            ->when($clientId > 0, fn ($query) => $query->where('client_id', $clientId))
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'roles' => Role::query()->orderByDesc('level')->get(),
            'clients' => Client::query()->orderBy('name')->get(),
            'pendingAccessRequests' => AccessRequest::query()->pending()->count(),
            'filters' => [
                'search' => $search,
                'role_id' => $roleId > 0 ? $roleId : null,
                'client_id' => $clientId > 0 ? $clientId : null,
                'status' => in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'active',
            ],
            'canManageAssignments' => $request->user()->isSuperAdmin(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function edit(Request $request, User $user): View
    {
        $this->ensureEditable($request->user(), $user);

        return view('users.edit', [
            'managedUser' => $user->load(['role', 'client']),
            'roles' => Role::query()->orderByDesc('level')->get(),
            'clients' => Client::query()->orderBy('name')->get(),
            'canManageAssignments' => $request->user()->isSuperAdmin(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function update(UpdateManagedUserRequest $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $this->ensureEditable($actor, $user);

        $validated = $request->validated();

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];

        if ($validated['email'] !== $user->email) {
            $payload['email_verified_at'] = null;
        }

        if ($actor->isSuperAdmin()) {
            $role = Role::query()->findOrFail($validated['role_id']);

            $payload['role_id'] = $role->id;
            $payload['client_id'] = $role->slug === Role::CLIENTE
                ? ($validated['client_id'] ?? null)
                : null;
            $payload['active'] = (bool) ($validated['active'] ?? false);
        }

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        $user->update($payload);

        return redirect()
            ->route('users.index')
            ->with('status', 'Usuario actualizado correctamente.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        abort_if($request->user()->is($user), 403);

        $user->update([
            'active' => ! $user->active,
        ]);

        return redirect()
            ->route('users.index')
            ->with('status', $user->active
                ? 'Usuario activado correctamente.'
                : 'Usuario desactivado correctamente.');
    }

    private function ensureEditable(User $actor, User $managedUser): void
    {
        abort_if(! $actor->isSuperAdmin() && $managedUser->isSuperAdmin(), 403);
    }
}
