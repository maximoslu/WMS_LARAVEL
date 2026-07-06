@extends('layouts.dashboard')

@section('title', 'Proveedores | MAXIMO WMS')
@section('topbar_title', 'Proveedores')

@section('content')
    @php
        $canManageSuppliers = auth()->user()->canAccessRole(\App\Models\Role::ALMACEN);
        $canToggleSuppliers = auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION);
        $breadcrumbs = [


        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => 'Gestion'],
        ['label' => 'Proveedores'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Proveedores</h2>
            <span class="ops-page-meta">{{ $suppliers->total() }} registros</span>
        </div>

        @if ($canManageSuppliers)
            <div class="ops-page-actions page-actions-compact action-buttons">
                <a href="{{ route('suppliers.create') }}" class="button-primary compact-button btn-compact">Nuevo proveedor</a>
            </div>
        @endif
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('suppliers.index') }}" class="stock-filters compact-filters filters-compact suppliers-filter-form">
            <label class="auth-field">
                <span>Nombre, CIF o contacto</span>
                <input type="text" name="search" value="{{ $filters['search'] }}" class="auth-input" placeholder="Buscar proveedor">
            </label>

            <label class="auth-field">
                <span>Cliente</span>
                <select name="client_id" class="auth-input">
                    <option value="">Todos</option>
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

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('suppliers.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($suppliers->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin resultados</span>
            <h3>No hay proveedores con estos filtros</h3>
            <p>Ajusta la busqueda o crea el primer proveedor para empezar a registrar entradas.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact" aria-label="Listado de proveedores">
                    <thead>
                        <tr>
                            <th>Proveedor</th>
                            <th>Cliente</th>
                            <th>CIF / NIF</th>
                            <th>Contacto</th>
                            <th>Email</th>
                            <th>Telefono</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($suppliers as $supplier)
                            <tr>
                                <td>
                                    <div class="stock-cell-main">
                                        <strong>{{ $supplier->name }}</strong>
                                        <span>{{ $supplier->notes ?: 'Sin notas' }}</span>
                                    </div>
                                </td>
                                <td>{{ $supplier->client?->name ?: 'Global MAXIMO' }}</td>
                                <td>{{ $supplier->tax_id ?: '-' }}</td>
                                <td>{{ $supplier->contact_name ?: '-' }}</td>
                                <td>{{ $supplier->email ?: '-' }}</td>
                                <td>{{ $supplier->phone ?: '-' }}</td>
                                <td>
                                    <span class="status-badge {{ $supplier->active ? 'status-badge--active' : 'status-badge--inactive' }}">
                                        {{ $supplier->active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td>
                                    @if ($canManageSuppliers)
                                        <div class="inline-actions action-buttons">
                                            <a href="{{ route('suppliers.edit', $supplier) }}" class="button-secondary compact-button btn-table">Editar</a>

                                            @if ($canToggleSuppliers)
                                                <form method="POST" action="{{ route('suppliers.toggle-active', $supplier) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="button-secondary compact-button btn-table">
                                                        {{ $supplier->active ? 'Desactivar' : 'Activar' }}
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-muted">Solo lectura</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($suppliers->hasPages())
        <div class="pagination-card surface-card compact-card">
            {{ $suppliers->links() }}
        </div>
    @endif
@endsection





