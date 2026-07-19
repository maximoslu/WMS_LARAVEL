@extends('layouts.dashboard')

@section('title', 'Usuarios | MAXIMO WMS')
@section('topbar_title', 'Usuarios')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Sistema'],
            ['label' => 'Usuarios'],
        ];

        $visibleUsers = $users->getCollection();
        $visibleActiveUsers = $visibleUsers->where('active', true)->count();
        $visibleInactiveUsers = $users->count() - $visibleActiveUsers;
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-admin-page wms-admin-users-page">
        <section class="surface-card compact-card wms-list-header wms-admin-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Administracion ligera</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Usuarios y roles</h2>
                    <span class="wms-list-count">{{ $users->total() }} registros</span>
                </div>
                <p class="wms-list-subtitle">
                    Consulta usuarios, rol operativo, cliente asignado y estado de acceso sin cambiar la configuracion desde el listado.
                </p>
            </div>

            <div class="wms-admin-header-side">
                <dl class="wms-list-metrics wms-admin-metrics">
                    <div>
                        <dt>Visibles</dt>
                        <dd>{{ $users->count() }}</dd>
                    </div>
                    <div>
                        <dt>Activos</dt>
                        <dd>{{ $visibleActiveUsers }}</dd>
                    </div>
                    <div>
                        <dt>Inactivos</dt>
                        <dd>{{ $visibleInactiveUsers }}</dd>
                    </div>
                </dl>

                <div class="wms-list-actions">
                    <a href="{{ route('access-requests.index') }}" class="button-secondary compact-button btn-compact">
                        Solicitudes de acceso
                        @if ($pendingAccessRequests > 0)
                            <span class="users-pending-count">{{ $pendingAccessRequests }}</span>
                        @endif
                    </a>
                </div>
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <section class="surface-card compact-card wms-filter-panel wms-admin-filter-panel">
            <form method="GET" action="{{ route('users.index') }}" class="item-filter-form compact-filters filters-compact users-filter-form wms-filter-grid wms-admin-filter-grid wms-admin-filter-grid--users">
                <label class="auth-field wms-filter-search">
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

                <div class="item-filter-actions action-buttons page-actions-compact wms-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                    <a href="{{ route('users.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                </div>
            </form>

            <div class="wms-filter-summary">
                @if ($filters['search'])
                    <span class="wms-filter-token">Busqueda: {{ $filters['search'] }}</span>
                @endif
                @if ($filters['role_id'])
                    <span class="wms-filter-token">Rol filtrado</span>
                @endif
                @if ($filters['client_id'])
                    <span class="wms-filter-token">Cliente filtrado</span>
                @endif
                <span class="wms-filter-muted">Estado: {{ match($filters['status']) {
                    'inactive' => 'Solo inactivos',
                    'all' => 'Todos',
                    default => 'Solo activos',
                } }}</span>
            </div>
        </section>

        @if (! $canManageAssignments)
            <article class="surface-card compact-card users-note wms-admin-note">
                <p>Administracion puede consultar y editar datos basicos. Solo superadmin puede asignar rol, cliente y activar o desactivar usuarios.</p>
            </article>
        @endif

        @if ($users->isEmpty())
            <article class="surface-card compact-card wms-empty-state wms-admin-empty">
                <div>
                    <span class="wms-status-chip wms-status-chip--neutral">Sin resultados</span>
                    <h3>No hay usuarios con estos filtros</h3>
                    <p>Ajusta los filtros para localizar usuarios registrados.</p>
                </div>
            </article>
        @else
            <section class="surface-card compact-card wms-table-panel wms-admin-table-panel">
                <div class="wms-table-toolbar">
                    <div>
                        <strong>Directorio de usuarios</strong>
                        <span>Mostrando {{ $users->firstItem() }}-{{ $users->lastItem() }} de {{ $users->total() }}</span>
                    </div>
                    <div class="wms-table-totals">
                        <span>{{ $pendingAccessRequests }} solicitudes pendientes</span>
                    </div>
                </div>

                <div class="data-table-wrap">
                    <table class="data-table table-compact wms-admin-table" aria-label="Listado de usuarios">
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
                                        <div class="stock-cell-main wms-admin-identity">
                                            <strong>{{ $managedUser->name }}</strong>
                                            <span class="users-table-email">{{ $managedUser->email }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="wms-role-chip wms-role-chip--{{ $managedUser->role?->slug ?? 'none' }}">
                                            {{ $managedUser->role?->name ?? 'Sin rol' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="wms-user-chip">
                                            {{ $managedUser->role?->slug === \App\Models\Role::CLIENTE
                                                ? ($managedUser->client?->name ?? 'Sin cliente')
                                                : 'Todos los clientes' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge {{ $managedUser->active ? 'status-badge--active' : 'status-badge--inactive' }}">
                                            {{ $managedUser->active ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="inline-actions action-buttons wms-row-actions">
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
    </div>
@endsection





