@extends('layouts.dashboard')

@section('title', 'Ubicaciones | MAXIMO WMS')
@section('topbar_title', 'Ubicaciones')

@section('content')
    @php
        $breadcrumbs = [


        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => 'Stock'],
        ['label' => 'Ubicaciones'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Ubicaciones</h2>
            <span class="ops-page-meta">{{ $locations->total() }} registros</span>
        </div>

        @if (auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION))
            <div class="ops-page-actions page-actions-compact action-buttons">
                <a href="{{ route('locations.create') }}" class="button-primary compact-button btn-compact">Nueva ubicacion</a>
            </div>
        @endif
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('locations.index') }}" class="stock-filters compact-filters filters-compact">
            <label class="auth-field">
                <span>Almacen</span>
                <select name="warehouse_id" class="auth-input">
                    <option value="">Todos los almacenes</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) $filters['warehouse_id'] === (string) $warehouse->id)>
                            {{ $warehouse->code }} / {{ $warehouse->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="auth-field">
                <span>Codigo o nombre</span>
                <input type="text" name="search" value="{{ $filters['search'] }}" class="auth-input" placeholder="Buscar ubicacion">
            </label>

            <label class="auth-field">
                <span>Estado</span>
                <select name="status" class="auth-input">
                    <option value="active" @selected($filters['status'] === 'active')>Solo activas</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Solo inactivas</option>
                    <option value="all" @selected($filters['status'] === 'all')>Todas</option>
                </select>
            </label>

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('locations.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($locations->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin resultados</span>
            <h3>No hay ubicaciones con estos filtros</h3>
            <p>Crea ubicaciones nuevas o ajusta almacen, estado y texto de busqueda.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact" aria-label="Listado de ubicaciones">
                    <thead>
                        <tr>
                            <th>Almacen</th>
                            <th>Codigo</th>
                            <th>Nombre</th>
                            <th>Zona</th>
                            <th>Estructura</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($locations as $location)
                            <tr>
                                <td>{{ $location->warehouse->code }}</td>
                                <td><strong>{{ $location->code }}</strong></td>
                                <td>{{ $location->name ?: '-' }}</td>
                                <td>{{ $location->zone ?: '-' }}</td>
                                <td>
                                    {{ collect([$location->aisle, $location->rack, $location->level, $location->position])->filter()->implode(' / ') ?: '-' }}
                                </td>
                                <td>
                                    <span class="status-badge {{ $location->active ? 'status-badge--active' : 'status-badge--inactive' }}">
                                        {{ $location->active ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>
                                <td>
                                    @if (auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION))
                                        <div class="inline-actions action-buttons">
                                            <a href="{{ route('locations.edit', $location) }}" class="button-secondary compact-button btn-table">Editar</a>
                                            <form method="POST" action="{{ route('locations.toggle-active', $location) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="button-secondary compact-button btn-table">
                                                    {{ $location->active ? 'Desactivar' : 'Activar' }}
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

    @if ($locations->hasPages())
        <div class="pagination-card surface-card compact-card">
            {{ $locations->links() }}
        </div>
    @endif
@endsection





