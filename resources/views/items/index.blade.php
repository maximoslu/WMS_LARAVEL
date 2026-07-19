@extends('layouts.dashboard')

@section('title', 'Articulos | MAXIMO WMS')
@section('topbar_title', 'Articulos')

@section('content')
    @php
        $isCardsView = $filters['view'] === 'cards';
        $canManageItems = auth()->user()->canAccessRole(\App\Models\Role::ALMACEN);
        $canToggleItems = auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION);
        $visibleItems = $items->getCollection();
        $visibleActiveItems = $visibleItems->where('status', \App\Models\Item::STATUS_ACTIVE)->count();
        $visibleBlockedItems = $visibleItems->where('status', \App\Models\Item::STATUS_BLOCKED)->count();
        $visibleObsoleteItems = $visibleItems->where('status', \App\Models\Item::STATUS_OBSOLETE)->count();
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Stock'],
            ['label' => 'Articulos'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-stock-admin-page wms-stock-items-page">
        <section class="surface-card compact-card wms-list-header wms-stock-admin-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Stock / Maestro de articulos</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Articulos</h2>
                    <span class="wms-list-count">{{ $items->total() }} registros</span>
                </div>
                <p class="wms-list-subtitle">
                    Catalogo de referencias por cliente, unidad por pallet, ubicacion por defecto y estado operativo.
                </p>
            </div>

            <div class="wms-stock-admin-header-side">
                <dl class="wms-list-metrics wms-stock-admin-metrics">
                    <div>
                        <dt>Visibles</dt>
                        <dd>{{ $items->count() }}</dd>
                    </div>
                    <div>
                        <dt>Activos</dt>
                        <dd>{{ $visibleActiveItems }}</dd>
                    </div>
                    <div>
                        <dt>Bloq./obs.</dt>
                        <dd>{{ $visibleBlockedItems + $visibleObsoleteItems }}</dd>
                    </div>
                </dl>

                <div class="ops-page-actions page-actions-compact action-buttons wms-list-actions">
                    @if ($canManageItems)
                        <a href="{{ route('items.create') }}" class="button-primary compact-button btn-compact">Nuevo articulo</a>
                    @endif
                </div>
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <section class="surface-card compact-card wms-filter-panel wms-stock-filter-panel">
            <div class="wms-stock-filter-toolbar">
                <form method="GET" action="{{ route('items.index') }}" class="item-filter-form compact-filters filters-compact wms-filter-grid wms-stock-filter-grid wms-stock-filter-grid--items">
                    <input type="hidden" name="view" value="{{ $filters['view'] }}">

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

                    <label class="auth-field wms-filter-search">
                        <span>SKU o descripcion</span>
                        <input
                            type="text"
                            name="search"
                            value="{{ $filters['search'] }}"
                            class="auth-input"
                            placeholder="Buscar articulo"
                        >
                    </label>

                    <label class="auth-field">
                        <span>Estado</span>
                        <select name="status" class="auth-input">
                            <option value="active" @selected($filters['status'] === 'active')>Activos</option>
                            <option value="blocked" @selected($filters['status'] === 'blocked')>Bloqueados</option>
                            <option value="obsolete" @selected($filters['status'] === 'obsolete')>Obsoletos</option>
                            <option value="all" @selected($filters['status'] === 'all')>Todos</option>
                        </select>
                    </label>

                    <div class="item-filter-actions action-buttons page-actions-compact wms-filter-actions">
                        <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                        <a href="{{ route('items.index', ['view' => $filters['view']]) }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                    </div>
                </form>

                <div class="view-switcher wms-item-view-toggle" aria-label="Selector de vista">
                    <a
                        href="{{ request()->fullUrlWithQuery(['view' => 'list']) }}"
                        class="view-switcher-link{{ $isCardsView ? '' : ' is-active' }}"
                    >
                        Lista
                    </a>
                    <a
                        href="{{ request()->fullUrlWithQuery(['view' => 'cards']) }}"
                        class="view-switcher-link{{ $isCardsView ? ' is-active' : '' }}"
                    >
                        Tarjetas
                    </a>
                </div>
            </div>

            <div class="wms-filter-summary" aria-label="Filtros aplicados">
                @if ($filters['client_id'])
                    <span class="wms-filter-token">Cliente seleccionado</span>
                @endif
                @if ($filters['search'])
                    <span class="wms-filter-token">Busqueda: {{ $filters['search'] }}</span>
                @endif
                <span class="wms-filter-muted">Estado: {{ match($filters['status']) {
                    'blocked' => 'Bloqueados',
                    'obsolete' => 'Obsoletos',
                    'all' => 'Todos',
                    default => 'Activos',
                } }}</span>
                <span class="wms-filter-muted">Vista: {{ $isCardsView ? 'Tarjetas' : 'Lista' }}</span>
            </div>
        </section>

        @if ($items->isEmpty())
            <article class="surface-card compact-card wms-empty-state wms-stock-empty">
                <div>
                    <span class="wms-status-chip wms-status-chip--neutral">Sin resultados</span>
                    <h3>No hay articulos con estos filtros</h3>
                    <p>Ajusta cliente, estado o texto de búsqueda para localizar artículos existentes.</p>
                </div>
                @if ($canManageItems)
                    <a href="{{ route('items.create') }}" class="button-primary compact-button btn-compact">Nuevo articulo</a>
                @endif
            </article>
        @elseif ($isCardsView)
            <section class="items-grid wms-stock-items-grid" aria-label="Vista tarjetas de articulos">
                @foreach ($items as $item)
                    <article class="surface-card item-card compact-card wms-stock-item-card">
                        <div class="item-card-header">
                            <div>
                                <span class="module-tag small-badge badge-compact">{{ $item->client->name }}</span>
                                <h3>{{ $item->sku }}</h3>
                            </div>
                            <span class="item-state item-state--{{ $item->status }} wms-status-chip wms-status-chip--{{ $item->status }}">
                                {{ $item->statusLabel() }}
                            </span>
                        </div>

                        <p class="item-card-description">{{ $item->description }}</p>

                        <dl class="item-card-metadata">
                            <div>
                                <dt>Ubicación por defecto</dt>
                                <dd>{{ $item->defaultLocation?->code ?: 'Sin ubicación' }}</dd>
                            </div>
                            <div>
                                <dt>Cantidad por pallet</dt>
                                <dd>{{ number_format($item->units_per_pallet, 0, ',', '.') }} uds/palet</dd>
                            </div>
                        </dl>

                        @if ($canManageItems)
                            <div class="item-card-actions action-buttons wms-row-actions">
                                <a href="{{ route('items.edit', $item) }}" class="button-secondary compact-button btn-table">Editar</a>

                                @if ($canToggleItems)
                                    <form method="POST" action="{{ route('items.toggle-active', $item) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="button-secondary compact-button btn-table">
                                            {{ $item->status === \App\Models\Item::STATUS_ACTIVE ? 'Bloquear' : 'Activar' }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </article>
                @endforeach
            </section>
        @else
            <section class="surface-card compact-card wms-table-panel wms-stock-table-panel">
                <div class="wms-table-toolbar">
                    <div>
                        <strong>Catalogo de articulos</strong>
                        <span>{{ number_format($items->firstItem() ?? 0, 0, ',', '.') }}-{{ number_format($items->lastItem() ?? 0, 0, ',', '.') }} de {{ number_format($items->total(), 0, ',', '.') }}</span>
                    </div>
                    <div class="wms-table-totals">
                        <span>{{ $visibleActiveItems }} activos visibles</span>
                    </div>
                </div>

                <div class="data-table-wrap wms-table-wrap">
                    <table class="data-table table-compact wms-stock-data-table" aria-label="Vista lista de articulos">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>SKU</th>
                                <th>Descripcion</th>
                                <th>Ubicación por defecto</th>
                                <th>Cantidad por palet</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $item)
                                <tr>
                                    <td>{{ $item->client->name }}</td>
                                    <td><strong>{{ $item->sku }}</strong></td>
                                    <td>{{ $item->description }}</td>
                                    <td>{{ $item->defaultLocation?->code ?: 'Sin ubicación' }}</td>
                                    <td>{{ number_format($item->units_per_pallet, 0, ',', '.') }} uds/palet</td>
                                    <td>
                                        <span class="status-badge item-status-badge item-status-badge--{{ $item->status }} wms-status-chip wms-status-chip--{{ $item->status }}">
                                            {{ $item->statusLabel() }}
                                        </span>
                                    </td>
                                    <td>
                                        @if ($canManageItems)
                                            <div class="inline-actions action-buttons wms-row-actions">
                                                <a href="{{ route('items.edit', $item) }}" class="button-secondary compact-button btn-table">Editar</a>

                                                @if ($canToggleItems)
                                                    <form method="POST" action="{{ route('items.toggle-active', $item) }}">
                                                        @csrf
                                                        @method('PATCH')
                                                        <button type="submit" class="button-secondary compact-button btn-table">
                                                            {{ $item->status === \App\Models\Item::STATUS_ACTIVE ? 'Bloquear' : 'Activar' }}
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

        @if ($items->hasPages())
            <div class="pagination-card surface-card compact-card">
                {{ $items->links() }}
            </div>
        @endif
    </div>
@endsection





