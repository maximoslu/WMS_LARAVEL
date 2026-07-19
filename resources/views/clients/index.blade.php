@extends('layouts.dashboard')

@section('title', 'Clientes | MAXIMO WMS')
@section('topbar_title', 'Clientes')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Gestion'],
            ['label' => 'Clientes'],
        ];

        $visibleClients = $clients->getCollection();
        $visibleActiveClients = $visibleClients->where('active', true)->count();
        $visibleInactiveClients = $clients->count() - $visibleActiveClients;
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-admin-page wms-admin-clients-page">
        <section class="surface-card compact-card wms-list-header wms-admin-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Maestro administrativo</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Clientes</h2>
                    <span class="wms-list-count">{{ $clients->total() }} registros</span>
                </div>
                <p class="wms-list-subtitle">
                    Directorio de clientes para revisar codigo, direccion de entrega, estado operativo y acceso a mantenimiento.
                </p>
            </div>

            <div class="wms-admin-header-side">
                <dl class="wms-list-metrics wms-admin-metrics">
                    <div>
                        <dt>Visibles</dt>
                        <dd>{{ $clients->count() }}</dd>
                    </div>
                    <div>
                        <dt>Activos</dt>
                        <dd>{{ $visibleActiveClients }}</dd>
                    </div>
                    <div>
                        <dt>Inactivos</dt>
                        <dd>{{ $visibleInactiveClients }}</dd>
                    </div>
                </dl>

                <div class="ops-page-actions page-actions-compact action-buttons wms-list-actions">
                    <a href="{{ route('clients.create') }}" class="button-primary compact-button btn-compact">Nuevo cliente</a>
                </div>
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <section class="surface-card compact-card wms-filter-panel wms-admin-filter-panel">
            <form method="GET" action="{{ route('clients.index') }}" class="item-filter-form compact-filters filters-compact wms-filter-grid wms-admin-filter-grid wms-admin-filter-grid--clients">
                <label class="auth-field wms-filter-search">
                    <span>Busqueda</span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="auth-input" placeholder="Nombre, codigo o ciudad">
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
                    <a href="{{ route('clients.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                </div>
            </form>

            <div class="wms-filter-summary">
                @if ($filters['search'])
                    <span class="wms-filter-token">Busqueda: {{ $filters['search'] }}</span>
                @endif
                <span class="wms-filter-muted">Estado: {{ match($filters['status']) {
                    'inactive' => 'Solo inactivos',
                    'all' => 'Todos',
                    default => 'Solo activos',
                } }}</span>
            </div>
        </section>

        @if ($clients->isEmpty())
            <article class="surface-card compact-card wms-empty-state wms-admin-empty">
                <div>
                    <span class="wms-status-chip wms-status-chip--neutral">Sin clientes</span>
                    <h3>No hay clientes con estos filtros</h3>
                    <p>Crea o ajusta un cliente para completar direccion de entrega y operativa de salidas.</p>
                </div>
                <a href="{{ route('clients.create') }}" class="button-primary compact-button btn-compact">Nuevo cliente</a>
            </article>
        @else
            <section class="surface-card compact-card wms-table-panel wms-admin-table-panel">
                <div class="wms-table-toolbar">
                    <div>
                        <strong>Maestro de clientes</strong>
                        <span>Mostrando {{ $clients->firstItem() }}-{{ $clients->lastItem() }} de {{ $clients->total() }}</span>
                    </div>
                    <div class="wms-table-totals">
                        <span>{{ $visibleActiveClients }} activos visibles</span>
                    </div>
                </div>

                <div class="data-table-wrap">
                    <table class="data-table table-compact wms-admin-table" aria-label="Listado de clientes">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Codigo</th>
                                <th>Direccion de entrega</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($clients as $client)
                                <tr>
                                    <td>
                                        <div class="stock-cell-main wms-admin-identity">
                                            <strong>{{ $client->name }}</strong>
                                            <span class="users-table-email">ID {{ $client->id }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="wms-user-chip wms-client-code">{{ $client->code }}</span>
                                    </td>
                                    <td>{{ $client->formattedDeliveryAddress() ?: 'Pendiente de completar' }}</td>
                                    <td>
                                        <span class="status-badge {{ $client->active ? 'status-badge--active' : 'status-badge--inactive' }}">
                                            {{ $client->active ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="inline-actions action-buttons wms-row-actions">
                                            <a href="{{ route('clients.edit', $client) }}" class="button-secondary compact-button btn-table">Editar</a>

                                            <form method="POST" action="{{ route('clients.toggle-active', $client) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="button-secondary compact-button btn-table">
                                                    {{ $client->active ? 'Desactivar' : 'Activar' }}
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($clients->hasPages())
                <div class="pagination-card surface-card compact-card">
                    {{ $clients->links() }}
                </div>
            @endif
        @endif
    </div>
@endsection





