@extends('layouts.dashboard')

@section('title', 'Articulos | MAXIMO WMS')

@section('content')
    <section class="items-hero">
        <article class="surface-card items-hero-card">
            <div class="app-copy">
                <span class="status-chip">Stock · Maestro</span>
                <h2 class="app-page-title">Articulos</h2>
                <p>El articulo define el paletizado estandar. El stock real y los picos se gestionaran en movimientos/palets.</p>
            </div>

            <div class="items-hero-actions">
                @if (auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION))
                    <a href="{{ route('items.create') }}" class="button-primary">Nuevo articulo</a>
                @endif
                <a href="{{ route('modules.stock') }}" class="button-secondary">Volver a Stock</a>
            </div>
        </article>

        <aside class="surface-card items-side-card">
            <div class="app-copy">
                <span class="module-tag">Modelo actual</span>
                <strong>Unidad operativa: palet</strong>
                <p>Cada articulo fija su cantidad estandar por palet. No existe aun stock real, ubicaciones ni movimientos en esta fase.</p>
            </div>
        </aside>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card">
        <form method="GET" action="{{ route('items.index') }}" class="item-filter-form">
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

            <div class="item-filter-actions">
                <button type="submit" class="button-primary">Filtrar</button>
                <a href="{{ route('items.index') }}" class="button-secondary">Limpiar</a>
            </div>
        </form>
    </section>

    <section class="items-grid" aria-label="Listado de articulos">
        @forelse ($items as $item)
            <article class="surface-card item-card">
                <div class="item-card-header">
                    <div>
                        <span class="module-tag">{{ $item->client->name }}</span>
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
                    <div class="item-card-actions">
                        <a href="{{ route('items.edit', $item) }}" class="button-secondary">Editar</a>

                        <form method="POST" action="{{ route('items.toggle-active', $item) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="button-secondary">
                                {{ $item->active ? 'Desactivar' : 'Activar' }}
                            </button>
                        </form>
                    </div>
                @endif
            </article>
        @empty
            <article class="surface-card item-empty-state">
                <span class="status-chip">Sin resultados</span>
                <h3>No hay articulos con estos filtros</h3>
                <p>Ajusta cliente, estado o texto de busqueda para localizar articulos existentes.</p>
            </article>
        @endforelse
    </section>

    @if ($items->hasPages())
        <div class="pagination-card surface-card">
            {{ $items->links() }}
        </div>
    @endif
@endsection
