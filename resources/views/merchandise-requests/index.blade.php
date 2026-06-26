@extends('layouts.dashboard')

@section('title', 'Solicitudes de mercancia | MAXIMO WMS')
@section('topbar_title', 'Solicitudes de mercancia')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <span>Operaciones</span>
        <span>/</span>
        <span>Solicitudes</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Solicitudes de mercancia</h2>
            <span class="ops-page-meta">{{ $requests->total() }} registros</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons">
            @if (! auth()->user()->canAccessRole(\App\Models\Role::ALMACEN) || auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION))
                <a href="{{ route('merchandise-requests.create') }}" class="button-primary compact-button btn-compact">Nueva solicitud</a>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('merchandise-requests.index') }}" class="stock-filters compact-filters filters-compact merchandise-requests-filter-form">
            @unless (auth()->user()->hasRole(\App\Models\Role::CLIENTE))
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
            @endunless

            <label class="auth-field">
                <span>Estado</span>
                <select name="status" class="auth-input">
                    <option value="all" @selected($filters['status'] === 'all')>Todos</option>
                    <option value="{{ \App\Models\MerchandiseRequest::STATUS_CREATED }}" @selected($filters['status'] === \App\Models\MerchandiseRequest::STATUS_CREATED)>Creada</option>
                    <option value="{{ \App\Models\MerchandiseRequest::STATUS_PREPARED }}" @selected($filters['status'] === \App\Models\MerchandiseRequest::STATUS_PREPARED)>Preparada</option>
                    <option value="{{ \App\Models\MerchandiseRequest::STATUS_SHIPPED }}" @selected($filters['status'] === \App\Models\MerchandiseRequest::STATUS_SHIPPED)>Enviada</option>
                    <option value="{{ \App\Models\MerchandiseRequest::STATUS_CANCELLED }}" @selected($filters['status'] === \App\Models\MerchandiseRequest::STATUS_CANCELLED)>Cancelada</option>
                </select>
            </label>

            <label class="auth-field">
                <span>Referencia</span>
                <input type="text" name="search" value="{{ $filters['search'] }}" class="auth-input" placeholder="Cliente o referencia">
            </label>

            <label class="auth-field">
                <span>Fecha</span>
                <input type="date" name="requested_date" value="{{ $filters['requested_date'] }}" class="auth-input">
            </label>

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('merchandise-requests.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($requests->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin resultados</span>
            <h3>No hay solicitudes con estos filtros</h3>
            <p>Crea una nueva solicitud o ajusta los filtros para localizar pedidos existentes.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact merchandise-requests-table" aria-label="Listado de solicitudes">
                    <thead>
                        <tr>
                            <th>Solicitud</th>
                            <th>Cliente</th>
                            <th>Solicitante</th>
                            <th>Fecha</th>
                            <th>Lineas</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $row)
                            <tr>
                                <td>
                                    <div class="stock-cell-main">
                                        <strong>{{ $row->delivery_reference ?: 'Solicitud #'.$row->id }}</strong>
                                        <span>{{ $row->delivery_address ? \Illuminate\Support\Str::limit($row->delivery_address, 72) : 'Sin direccion' }}</span>
                                    </div>
                                </td>
                                <td>{{ $row->client->name }}</td>
                                <td>{{ $row->requester?->name ?: 'Usuario no disponible' }}</td>
                                <td>{{ optional($row->requested_date)->format('d/m/Y') ?: '-' }}</td>
                                <td>{{ number_format($row->lines_count, 0, ',', '.') }}</td>
                                <td>
                                    <span class="request-status-pill request-status-pill--{{ $row->status }}">{{ $row->statusLabel() }}</span>
                                </td>
                                <td>
                                    <div class="inline-actions action-buttons">
                                        <a href="{{ route('merchandise-requests.show', $row) }}" class="button-secondary compact-button btn-table">Ver</a>
                                        @if ($row->isEditableByClient() && (auth()->user()->hasRole(\App\Models\Role::CLIENTE) || auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION)))
                                            <a href="{{ route('merchandise-requests.edit', $row) }}" class="button-secondary compact-button btn-table">Editar</a>
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

    @if ($requests->hasPages())
        <div class="pagination-card surface-card compact-card">
            {{ $requests->links() }}
        </div>
    @endif
@endsection
