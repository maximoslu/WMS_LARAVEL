@extends('layouts.dashboard')

@section('title', 'Ubicaciones | MAXIMO WMS')
@section('topbar_title', 'Ubicaciones')

@section('content')
    @php
        $canManageLocations = auth()->user()->canAccessRole(\App\Models\Role::ALMACEN);
        $canToggleLocations = auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION);
        $visibleLocations = $locations->getCollection();
        $visibleActiveLocations = $visibleLocations->where('active', true)->count();
        $visibleInactiveLocations = $locations->count() - $visibleActiveLocations;
        $visibleWarehouses = $visibleLocations->pluck('warehouse_id')->unique()->count();
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Stock'],
            ['label' => 'Ubicaciones'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-stock-admin-page wms-stock-locations-page">
        <section class="surface-card compact-card wms-list-header wms-stock-admin-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Stock / Maestro de ubicaciones</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Ubicaciones</h2>
                    <span class="wms-list-count">{{ $locations->total() }} registros</span>
                </div>
                <p class="wms-list-subtitle">
                    Mapa maestro de huecos por almacen, zona y estructura fisica, manteniendo la ordenacion natural existente.
                </p>
            </div>

            <div class="wms-stock-admin-header-side">
                <dl class="wms-list-metrics wms-stock-admin-metrics">
                    <div>
                        <dt>Visibles</dt>
                        <dd>{{ $locations->count() }}</dd>
                    </div>
                    <div>
                        <dt>Activas</dt>
                        <dd>{{ $visibleActiveLocations }}</dd>
                    </div>
                    <div>
                        <dt>Almacenes</dt>
                        <dd>{{ $visibleWarehouses }}</dd>
                    </div>
                </dl>

                @if ($canManageLocations)
                    <div class="ops-page-actions page-actions-compact action-buttons wms-list-actions">
                        <a href="{{ route('locations.create') }}" class="button-primary compact-button btn-compact">Nueva ubicacion</a>
                    </div>
                @endif
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <section class="surface-card compact-card wms-filter-panel wms-stock-filter-panel">
            <form method="GET" action="{{ route('locations.index') }}" class="stock-filters compact-filters filters-compact wms-filter-grid wms-stock-filter-grid wms-stock-filter-grid--locations">
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

                <label class="auth-field wms-filter-search">
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

                <div class="stock-filter-actions action-buttons page-actions-compact wms-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                    <a href="{{ route('locations.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                </div>
            </form>

            <div class="wms-filter-summary" aria-label="Filtros aplicados">
                @if ($filters['warehouse_id'])
                    <span class="wms-filter-token">Almacen seleccionado</span>
                @endif
                @if ($filters['search'])
                    <span class="wms-filter-token">Busqueda: {{ $filters['search'] }}</span>
                @endif
                <span class="wms-filter-muted">Estado: {{ match($filters['status']) {
                    'inactive' => 'Solo inactivas',
                    'all' => 'Todas',
                    default => 'Solo activas',
                } }}</span>
            </div>
        </section>

        @if ($locations->isEmpty())
            <article class="surface-card compact-card wms-empty-state wms-stock-empty">
                <div>
                    <span class="wms-status-chip wms-status-chip--neutral">Sin resultados</span>
                    <h3>No hay ubicaciones con estos filtros</h3>
                    <p>Crea ubicaciones nuevas o ajusta almacen, estado y texto de busqueda.</p>
                </div>
                @if ($canManageLocations)
                    <a href="{{ route('locations.create') }}" class="button-primary compact-button btn-compact">Nueva ubicacion</a>
                @endif
            </article>
        @else
            <section class="surface-card compact-card wms-table-panel wms-stock-table-panel">
                <div class="wms-table-toolbar">
                    <div>
                        <strong>Mapa de ubicaciones</strong>
                        <span>{{ number_format($locations->firstItem() ?? 0, 0, ',', '.') }}-{{ number_format($locations->lastItem() ?? 0, 0, ',', '.') }} de {{ number_format($locations->total(), 0, ',', '.') }}</span>
                    </div>
                    <div class="wms-table-totals">
                        <span>{{ $visibleInactiveLocations }} inactivas visibles</span>
                    </div>
                </div>

                <div class="data-table-wrap wms-table-wrap">
                    <table class="data-table table-compact wms-stock-data-table" aria-label="Listado de ubicaciones">
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
                                    <td><span class="wms-location-chip">{{ $location->warehouse->code }}</span></td>
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
                                        @if ($canManageLocations)
                                            <div class="inline-actions action-buttons wms-row-actions">
                                                <a href="{{ route('locations.edit', $location) }}" class="button-secondary compact-button btn-table">Editar</a>

                                                @if ($canToggleLocations)
                                                    <form method="POST" action="{{ route('locations.toggle-active', $location) }}">
                                                        @csrf
                                                        @method('PATCH')
                                                        <button type="submit" class="button-secondary compact-button btn-table">
                                                            {{ $location->active ? 'Desactivar' : 'Activar' }}
                                                        </button>
                                                    </form>
                                                @endif
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
    </div>
@endsection
