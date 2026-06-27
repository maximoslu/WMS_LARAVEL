@extends('layouts.dashboard')

@section('title', 'Usuarios | MAXIMO WMS')
@section('topbar_title', 'Usuarios')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Sistema</span>
        <span>/</span>
        <span>Usuarios</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Usuarios y roles</h2>
            <span class="ops-page-meta">{{ $users->total() }} registros</span>
        </div>
        <div class="item-filter-actions action-buttons page-actions-compact">
            <a href="{{ route('access-requests.index') }}" class="button-secondary compact-button btn-compact">
                Solicitudes de acceso
                @if ($pendingAccessRequests > 0)
                    <span class="users-pending-count">{{ $pendingAccessRequests }}</span>
                @endif
            </a>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('users.index') }}" class="item-filter-form compact-filters filters-compact users-filter-form">
            <label class="auth-field">
                <span>Nombre o email</span>
                <input type="text" name="search" value="{{ $filters['search'] }}" class="auth-input" placeholder="Buscar usuario">
            </label>

            <label class="auth-field">
                <span>Rol</span>
                <select name="role_id" class="auth-input">
                    <option value="">Todos los roles</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}" @selected((string) $filters['role_id'] === (string) $role->id)>
                            {{ $role->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="auth-field">
                <span>Cliente</span>
                <select name="client_id" class="auth-input">
                    <option value="">Todos los clientes</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) $filters['client_id'] === (string) $client->id)>
                            {{ $client->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="auth-field">
                <span>Estado</span>
                <select name="status" class="auth-input">
                    <option value="active" @selected($filters['status'] === 'active')>Solo activos</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Solo inactivos</option>
                    <option value="all" @selected($filters['status'] === 'all')>Todos</option>
                </select>
            </label>

            <div class="item-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('users.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if (! $canManageAssignments)
        <article class="surface-card compact-card users-note">
            <p>Administracion puede consultar y editar datos basicos. Solo superadmin puede asignar rol, cliente y activar o desactivar usuarios.</p>
        </article>
    @endif

    @if ($users->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin resultados</span>
            <h3>No hay usuarios con estos filtros</h3>
            <p>Ajusta los filtros para localizar usuarios registrados.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact" aria-label="Listado de usuarios">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>Cliente</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $managedUser)
                            <tr>
                                <td>
                                    <div class="stock-cell-main">
                                        <strong>{{ $managedUser->name }}</strong>
                                        <span class="users-table-email">{{ $managedUser->email }}</span>
                                    </div>
                                </td>
                                <td>{{ $managedUser->role?->name ?? 'Sin rol' }}</td>
                                <td>{{ $managedUser->client?->name ?? 'Sin cliente' }}</td>
                                <td>
                                    <span class="status-badge {{ $managedUser->active ? 'status-badge--active' : 'status-badge--inactive' }}">
                                        {{ $managedUser->active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="inline-actions action-buttons">
                                        <a href="{{ route('users.edit', $managedUser) }}" class="button-secondary compact-button btn-table">Editar</a>

                                        @if (auth()->user()->isSuperAdmin() && ! auth()->user()->is($managedUser))
                                            <form method="POST" action="{{ route('users.toggle-active', $managedUser) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="button-secondary compact-button btn-table">
                                                    {{ $managedUser->active ? 'Desactivar' : 'Activar' }}
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($users->hasPages())
        <div class="pagination-card surface-card compact-card">
            {{ $users->links() }}
        </div>
    @endif
@endsection
