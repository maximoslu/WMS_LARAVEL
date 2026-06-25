@extends('layouts.dashboard')

@section('title', 'Articulos | MAXIMO WMS')
@section('topbar_title', 'Articulos')

@section('content')
    @php($isCardsView = $filters['view'] === 'cards')

    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <span>Stock</span>
        <span>/</span>
        <span>Articulos</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="app-copy">
            <span class="status-chip small-badge badge-compact">Articulos</span>
            <h2 class="ops-page-title page-title-compact">Articulos</h2>
            <p>Vista lista por defecto para consulta y mantenimiento rapido.</p>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons">
            @if (auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION))
                <a href="{{ route('items.create') }}" class="button-primary compact-button btn-compact">Nuevo articulo</a>
            @endif
            <a href="{{ route('stock.index') }}" class="button-secondary compact-button btn-compact">Volver a stock</a>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <div class="data-toolbar compact-toolbar toolbar-compact">
            <form method="GET" action="{{ route('items.index') }}" class="item-filter-form compact-filters filters-compact">
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

                <label class="auth-field">
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
                        <option value="active" @selected($filters['status'] === 'active')>Solo activos</option>
                        <option value="inactive" @selected($filters['status'] === 'inactive')>Solo inactivos</option>
                        <option value="all" @selected($filters['status'] === 'all')>Todos</option>
                    </select>
                </label>

                <div class="item-filter-actions action-buttons page-actions-compact">
                    <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                    <a href="{{ route('items.index', ['view' => $filters['view']]) }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                </div>
            </form>

            <div class="view-switcher" aria-label="Selector de vista">
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
    </section>

    @if ($items->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin resultados</span>
            <h3>No hay articulos con estos filtros</h3>
            <p>Ajusta cliente, estado o texto de busqueda para localizar articulos existentes.</p>
        </article>
    @elseif ($isCardsView)
        <section class="items-grid" aria-label="Vista tarjetas de articulos">
            @foreach ($items as $item)
                <article class="surface-card item-card compact-card">
                    <div class="item-card-header">
                        <div>
                            <span class="module-tag small-badge badge-compact">{{ $item->client->name }}</span>
                            <h3>{{ $item->sku }}</h3>
                        </div>
                        <span class="item-state {{ $item->active ? 'item-state--active' : 'item-state--inactive' }}">
                            {{ $item->active ? 'Activo' : 'Inactivo' }}
                        </span>
                    </div>

                    <p class="item-card-description">{{ $item->description }}</p>

                    <dl class="item-card-metadata">
                        <div>
                            <dt>Lote</dt>
                            <dd>{{ $item->lot ?: 'Sin lote' }}</dd>
                        </div>
                        <div>
                            <dt>Cantidad por palet</dt>
                            <dd>{{ number_format($item->units_per_pallet, 0, ',', '.') }} uds/palet</dd>
                        </div>
                    </dl>

                    @if (auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION))
                        <div class="item-card-actions action-buttons">
                            <a href="{{ route('items.edit', $item) }}" class="button-secondary compact-button btn-table">Editar</a>

                            <form method="POST" action="{{ route('items.toggle-active', $item) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="button-secondary compact-button btn-table">
                                    {{ $item->active ? 'Desactivar' : 'Activar' }}
                                </button>
                            </form>
                        </div>
                    @endif
                </article>
            @endforeach
        </section>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact" aria-label="Vista lista de articulos">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>SKU</th>
                            <th>Descripcion</th>
                            <th>Lote</th>
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
                                <td>{{ $item->lot ?: 'Sin lote' }}</td>
                                <td>{{ number_format($item->units_per_pallet, 0, ',', '.') }} uds/palet</td>
                                <td>
                                    <span class="status-badge {{ $item->active ? 'status-badge--active' : 'status-badge--inactive' }}">
                                        {{ $item->active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td>
                                    @if (auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION))
                                        <div class="inline-actions action-buttons">
                                            <a href="{{ route('items.edit', $item) }}" class="button-secondary compact-button btn-table">Editar</a>

                                            <form method="POST" action="{{ route('items.toggle-active', $item) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="button-secondary compact-button btn-table">
                                                    {{ $item->active ? 'Desactivar' : 'Activar' }}
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

    @if ($items->hasPages())
        <div class="pagination-card surface-card compact-card">
            {{ $items->links() }}
        </div>
    @endif
@endsection
