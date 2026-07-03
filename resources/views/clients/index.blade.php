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
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Clientes</h2>
            <span class="ops-page-meta">{{ $clients->total() }} registros</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons">
            <a href="{{ route('clients.create') }}" class="button-primary compact-button btn-compact">Nuevo cliente</a>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('clients.index') }}" class="item-filter-form compact-filters filters-compact">
            <label class="auth-field">
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

            <div class="item-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('clients.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($clients->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin clientes</span>
            <h3>No hay clientes con estos filtros</h3>
            <p>Crea o ajusta un cliente para completar direccion de entrega y operativa de salidas.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact" aria-label="Listado de clientes">
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
                                <td><strong>{{ $client->name }}</strong></td>
                                <td>{{ $client->code }}</td>
                                <td>{{ $client->formattedDeliveryAddress() ?: 'Pendiente de completar' }}</td>
                                <td>
                                    <span class="status-badge {{ $client->active ? 'status-badge--active' : 'status-badge--inactive' }}">
                                        {{ $client->active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="inline-actions action-buttons">
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
@endsection





