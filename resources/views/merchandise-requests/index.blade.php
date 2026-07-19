@extends('layouts.dashboard')

@section('title', 'PEDIDOS | MAXIMO WMS')
@section('topbar_title', 'PEDIDOS')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Operaciones'],
            ['label' => 'PEDIDOS'],
        ];
        $visibleFilters = collect([
            ! $isClient && filled($filters['client_id']) ? 'Cliente seleccionado' : null,
            $filters['status'] !== 'all' ? 'Estado: '.(\App\Models\MerchandiseRequest::statusOptions()[$filters['status']] ?? $filters['status']) : null,
            filled($filters['search']) ? 'Busqueda: '.$filters['search'] : null,
        ])->filter();
        $totalPallets = $requests->getCollection()->sum(fn ($request) => $request->requestedPalletsCount());
        $totalPeaks = $requests->getCollection()->sum(fn ($request) => $request->requestedPeaksCount());
        $totalUnits = $requests->getCollection()->sum(fn ($request) => (int) $request->lines->sum('requested_units'));
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page merchandise-requests-list">
        <section class="surface-card compact-card wms-list-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Operaciones / Pedidos</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">
                        {{ $isClient ? 'Pedidos' : 'Pedidos recibidos' }}
                    </h2>
                    <span class="wms-list-count">{{ number_format($requests->total(), 0, ',', '.') }} registros</span>
                </div>
                <p class="wms-list-subtitle">
                    {{ $isClient ? 'Seguimiento de solicitudes de mercancia y expediciones asociadas.' : 'Bandeja operativa de pedidos de cliente pendientes, en preparacion y expedidos.' }}
                </p>
            </div>

            <div class="wms-list-actions">
                <dl class="wms-list-metrics" aria-label="Resumen visible">
                    <div>
                        <dt>En pagina</dt>
                        <dd>{{ number_format($requests->count(), 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt>Pallets</dt>
                        <dd>{{ number_format($totalPallets, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt>Picos</dt>
                        <dd>{{ number_format($totalPeaks, 0, ',', '.') }}</dd>
                    </div>
                </dl>

                @if ($canCreate)
                    <a href="{{ route('merchandise-requests.create') }}" class="button-primary compact-button btn-compact wms-list-primary-action">
                        Nuevo pedido
                    </a>
                @endif
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <section class="surface-card compact-card wms-filter-panel">
            <form method="GET" action="{{ route('merchandise-requests.index') }}" class="wms-filter-grid">
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
                        <option value="preparing" @selected($filters['status'] === 'preparing')>En preparacion</option>
                        <option value="sent" @selected($filters['status'] === 'sent')>Enviado</option>
                        <option value="completed" @selected($filters['status'] === 'completed')>Completado</option>
                        <option value="cancelled" @selected($filters['status'] === 'cancelled')>Cancelado</option>
                    </select>
                </label>

                <label class="auth-field wms-filter-search">
                    <span>Pedido, SKU o descripcion</span>
                    <input
                        type="text"
                        name="search"
                        value="{{ $filters['search'] }}"
                        class="auth-input"
                        placeholder="Buscar pedido"
                    >
                </label>

                <div class="wms-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                    <a href="{{ route('merchandise-requests.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                </div>
            </form>

            <div class="wms-filter-summary" aria-label="Filtros aplicados">
                @if ($visibleFilters->isNotEmpty())
                    @foreach ($visibleFilters as $visibleFilter)
                        <span class="wms-filter-token">{{ $visibleFilter }}</span>
                    @endforeach
                @else
                    <span class="wms-filter-muted">Sin filtros aplicados</span>
                @endif
            </div>
        </section>

        @if ($requests->isEmpty())
            <article class="surface-card compact-card wms-empty-state">
                <span class="wms-status-chip wms-status-chip--neutral">Sin pedidos</span>
                <div>
                    <h3>{{ $isClient ? 'Sin pedidos registrados.' : 'Sin pedidos con estos filtros.' }}</h3>
                    <p>{{ $isClient ? 'Cuando registres un pedido aparecera aqui con su estado operativo.' : 'Ajusta cliente, estado o busqueda para ampliar el resultado.' }}</p>
                </div>
                @if ($canCreate)
                    <a href="{{ route('merchandise-requests.create') }}" class="button-primary compact-button btn-compact">
                        Nuevo pedido
                    </a>
                @endif
            </article>
        @else
            <section class="surface-card compact-card wms-table-panel">
                <div class="wms-table-toolbar">
                    <div>
                        <strong>Listado operativo</strong>
                        <span>{{ number_format($requests->firstItem() ?? 0, 0, ',', '.') }}-{{ number_format($requests->lastItem() ?? 0, 0, ',', '.') }} de {{ number_format($requests->total(), 0, ',', '.') }}</span>
                    </div>
                    <div class="wms-table-totals" aria-label="Totales visibles">
                        <span>{{ number_format($totalUnits, 0, ',', '.') }} uds</span>
                    </div>
                </div>

                <div class="wms-table-wrap">
                    <table class="wms-data-table wms-request-table" aria-label="Listado de pedidos">
                        <thead>
                            <tr>
                                <th>Pedido</th>
                                @unless ($isClient)
                                    <th>Cliente</th>
                                    <th>Solicitante</th>
                                @endunless
                                <th>Fecha</th>
                                <th>Resumen</th>
                                <th class="wms-table-number">Pallets</th>
                                <th class="wms-table-number">Picos</th>
                                <th class="wms-table-number">Unidades</th>
                                <th>Salida</th>
                                <th>Estado</th>
                                <th class="wms-table-actions-cell">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requests as $merchandiseRequest)
                                <tr>
                                    <td>
                                        <div class="wms-request-code">
                                            <strong>{{ $merchandiseRequest->referenceCode() }}</strong>
                                            <span>{{ $merchandiseRequest->lines->count() }} lineas</span>
                                        </div>
                                    </td>
                                    @unless ($isClient)
                                        <td>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</td>
                                        <td>{{ $merchandiseRequest->requestedBy?->name ?? 'Sin usuario' }}</td>
                                    @endunless
                                    <td>{{ $merchandiseRequest->submittedAt()?->format('d/m/Y H:i') ?? '-' }}</td>
                                    <td>
                                        <div class="wms-request-summary">
                                            <strong>{{ $merchandiseRequest->lines->take(2)->map(fn ($line) => $line->item?->sku ?? 'Articulo')->implode(', ') ?: 'Sin lineas' }}</strong>
                                            @if ($merchandiseRequest->lines->count() > 2)
                                                <span>+{{ $merchandiseRequest->lines->count() - 2 }} mas</span>
                                            @else
                                                <span>{{ $merchandiseRequest->lines->count() }} lineas</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="wms-table-number">{{ number_format($merchandiseRequest->requestedPalletsCount(), 0, ',', '.') }}</td>
                                    <td class="wms-table-number">{{ number_format($merchandiseRequest->requestedPeaksCount(), 0, ',', '.') }}</td>
                                    <td class="wms-table-number">{{ number_format((int) $merchandiseRequest->lines->sum('requested_units'), 0, ',', '.') }}</td>
                                    <td>
                                        @if ($merchandiseRequest->dispatch)
                                            @unless ($isClient)
                                                <a href="{{ route('dispatches.show', $merchandiseRequest->dispatch) }}" class="wms-line-type-pill wms-line-type-pill--pallet">
                                                    {{ $merchandiseRequest->dispatch->dispatchNumber() }}
                                                </a>
                                            @else
                                                <span class="wms-line-type-pill wms-line-type-pill--pallet">{{ $merchandiseRequest->dispatch->dispatchNumber() }}</span>
                                            @endunless
                                        @else
                                            <span class="wms-muted-value">Sin salida</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="wms-status-chip wms-status-chip--{{ $merchandiseRequest->status }} merchandise-request-status merchandise-request-status--{{ $merchandiseRequest->status }}">
                                            {{ $merchandiseRequest->statusLabel() }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="wms-row-actions">
                                            <a href="{{ route('merchandise-requests.show', $merchandiseRequest) }}" class="button-secondary compact-button btn-table">
                                                Ver
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
    </div>
@endsection
