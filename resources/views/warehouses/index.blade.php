@extends('layouts.dashboard')

@section('title', 'Almacenes | MAXIMO WMS')
@section('topbar_title', 'Almacenes')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Gestión</span>
        <span>/</span>
        <span>Almacenes</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Almacenes</h2>
            <span class="ops-page-meta">{{ $warehouses->total() }} registros</span>
        </div>

        @if (auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION))
            <div class="ops-page-actions page-actions-compact action-buttons">
                <a href="{{ route('warehouses.create') }}" class="button-primary compact-button btn-compact">Nuevo almacen</a>
            </div>
        @endif
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('warehouses.index') }}" class="stock-filters compact-filters filters-compact">
            <label class="auth-field">
                <span>Codigo o nombre</span>
                <input type="text" name="search" value="{{ $filters['search'] }}" class="auth-input" placeholder="Buscar almacen">
            </label>

            <label class="auth-field">
                <span>Estado</span>
                <select name="status" class="auth-input">
                    <option value="active" @selected($filters['status'] === 'active')>Solo activos</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Solo inactivos</option>
                    <option value="all" @selected($filters['status'] === 'all')>Todos</option>
                </select>
            </label>

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('warehouses.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($warehouses->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin resultados</span>
            <h3>No hay almacenes con estos filtros</h3>
            <p>Crea el primer almacen operativo o ajusta la busqueda.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact" aria-label="Listado de almacenes">
                    <thead>
                        <tr>
                            <th>Ambito</th>
                            <th>Codigo</th>
                            <th>Nombre</th>
                            <th>Ubicaciones</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($warehouses as $warehouse)
                            <tr>
                                <td>{{ $warehouse->client?->name ?: 'Global MAXIMO' }}</td>
                                <td><strong>{{ $warehouse->code }}</strong></td>
                                <td>{{ $warehouse->name }}</td>
                                <td>{{ number_format($warehouse->locations->count(), 0, ',', '.') }}</td>
                                <td>
                                    <span class="status-badge {{ $warehouse->active ? 'status-badge--active' : 'status-badge--inactive' }}">
                                        {{ $warehouse->active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td>
                                    @if (auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION))
                                        <div class="inline-actions action-buttons">
                                            <a href="{{ route('warehouses.edit', $warehouse) }}" class="button-secondary compact-button btn-table">Editar</a>
                                            <form method="POST" action="{{ route('warehouses.toggle-active', $warehouse) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="button-secondary compact-button btn-table">
                                                    {{ $warehouse->active ? 'Desactivar' : 'Activar' }}
                                                </button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-muted">Sin acciones</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($warehouses->hasPages())
        <div class="pagination-card surface-card compact-card">
            {{ $warehouses->links() }}
        </div>
    @endif
@endsection
