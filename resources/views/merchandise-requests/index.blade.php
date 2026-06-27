@extends('layouts.dashboard')

@section('title', 'Solicitudes de mercancía | MAXIMO WMS')
@section('topbar_title', 'Solicitudes de mercancía')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Operaciones</span>
        <span>/</span>
        <span>Solicitudes de mercancía</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">
                {{ $isClient ? 'Mis solicitudes de mercancía' : 'Solicitudes de mercancía recibidas' }}
            </h2>
            <span class="ops-page-meta">{{ $requests->total() }} registros</span>
        </div>

        @if ($canCreate)
            <div class="ops-page-actions page-actions-compact action-buttons">
                <a href="{{ route('merchandise-requests.create') }}" class="button-primary compact-button btn-compact">
                    Solicitar mercancía
                </a>
            </div>
        @endif
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('merchandise-requests.index') }}" class="item-filter-form compact-filters filters-compact">
            @unless ($isClient)
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
            @endunless

            <label class="auth-field">
                <span>Estado</span>
                <select name="status" class="auth-input">
                    <option value="all" @selected($filters['status'] === 'all')>Todos</option>
                    <option value="pending" @selected($filters['status'] === 'pending')>Pendiente</option>
                    <option value="preparing" @selected($filters['status'] === 'preparing')>En preparación</option>
                    <option value="sent" @selected($filters['status'] === 'sent')>Enviado</option>
                    <option value="completed" @selected($filters['status'] === 'completed')>Completado</option>
                    <option value="cancelled" @selected($filters['status'] === 'cancelled')>Cancelado</option>
                </select>
            </label>

            <label class="auth-field">
                <span>Codigo, SKU o descripcion</span>
                <input
                    type="text"
                    name="search"
                    value="{{ $filters['search'] }}"
                    class="auth-input"
                    placeholder="Buscar solicitud"
                >
            </label>

            <div class="item-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('merchandise-requests.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($requests->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin solicitudes</span>
            <h3>{{ $isClient ? 'Todavía no has enviado pedidos' : 'No hay solicitudes con estos filtros' }}</h3>
            <p>
                {{ $isClient
                    ? 'Cuando selecciones pallets y envíes tu pedido, aparecerá aquí para que puedas consultarlo.'
                    : 'Ajusta los filtros para localizar solicitudes pendientes o historicas.' }}
            </p>
            @if ($canCreate)
                <div class="item-filter-actions action-buttons page-actions-compact">
                    <a href="{{ route('merchandise-requests.create') }}" class="button-primary compact-button btn-compact">
                        Crear solicitud
                    </a>
                </div>
            @endif
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact merchandise-requests-table" aria-label="Listado de solicitudes de mercancía">
                    <thead>
                        <tr>
                            <th>Codigo</th>
                            @unless ($isClient)
                                <th>Cliente</th>
                                <th>Solicitante</th>
                            @endunless
                            <th>Fecha</th>
                            <th>Resumen</th>
                            <th>Total pallets</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $merchandiseRequest)
                            <tr>
                                <td><strong>{{ $merchandiseRequest->referenceCode() }}</strong></td>
                                @unless ($isClient)
                                    <td>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</td>
                                    <td>{{ $merchandiseRequest->requestedBy?->name ?? 'Sin usuario' }}</td>
                                @endunless
                                <td>{{ $merchandiseRequest->submittedAt()?->format('Y-m-d H:i') }}</td>
                                <td>
                                    <div class="stock-cell-main">
                                        <strong>{{ $merchandiseRequest->lines->count() }} líneas</strong>
                                        <span class="users-table-email">
                                            {{ $merchandiseRequest->lines->take(2)->map(fn ($line) => $line->item?->sku ?? 'Articulo')->implode(', ') }}
                                            @if ($merchandiseRequest->lines->count() > 2)
                                                +{{ $merchandiseRequest->lines->count() - 2 }} mas
                                            @endif
                                        </span>
                                    </div>
                                </td>
                                <td>{{ number_format($merchandiseRequest->requestedPalletsCount(), 0, ',', '.') }}</td>
                                <td>
                                    <span class="status-badge merchandise-request-status merchandise-request-status--{{ $merchandiseRequest->status }}">
                                        {{ $merchandiseRequest->statusLabel() }}
                                    </span>
                                </td>
                                <td>
                                    <div class="inline-actions action-buttons">
                                        <a href="{{ route('merchandise-requests.show', $merchandiseRequest) }}" class="button-secondary compact-button btn-table">
                                            Ver detalle
                                        </a>
                                        @unless ($isClient)
                                            <a href="{{ route('dispatches.requests.show', $merchandiseRequest) }}" class="button-secondary compact-button btn-table">
                                                Gestionar
                                            </a>
                                        @endunless
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
