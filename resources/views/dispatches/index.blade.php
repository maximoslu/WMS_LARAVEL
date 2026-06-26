@extends('layouts.dashboard')

@section('title', 'Salidas | MAXIMO WMS')
@section('topbar_title', 'Salida de mercancia')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <span>Operaciones</span>
        <span>/</span>
        <span>Salidas</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Salida de mercancia</h2>
            <span class="ops-page-meta">{{ $dispatches->total() }} salidas registradas</span>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="dispatch-entry-grid">
        <article class="surface-card compact-card dispatch-entry-card">
            <span class="status-chip small-badge badge-compact">Desde pedido</span>
            <h3>Salida desde pedido pendiente</h3>
            <p>Revisa solicitudes pendientes, imprime preparacion y genera la salida sin duplicados.</p>
            <a href="{{ route('dispatches.requests.index') }}" class="button-primary compact-button btn-compact">Ver pedidos pendientes</a>
        </article>

        <article class="surface-card compact-card dispatch-entry-card">
            <span class="status-chip small-badge badge-compact">Manual</span>
            <h3>Salida manual</h3>
            <p>Selecciona cliente, anade mercancias y registra una salida documental directa.</p>
            <a href="{{ route('dispatches.create') }}" class="button-primary compact-button btn-compact">Crear salida manual</a>
        </article>
    </section>

    <section class="surface-card compact-card">
        <div class="ops-section-heading">
            <div>
                <strong>Pedidos pendientes destacados</strong>
                <p class="merchandise-request-summary-copy">Solicitudes listas para revisar o convertir en salida.</p>
            </div>
            <a href="{{ route('dispatches.requests.index') }}" class="button-secondary compact-button btn-table">Ver todos</a>
        </div>

        @if ($pendingRequests->isEmpty())
            <div class="merchandise-request-summary-empty">No hay solicitudes pendientes ahora mismo.</div>
        @else
            <div class="dispatch-pending-list">
                @foreach ($pendingRequests as $pendingRequest)
                    <article class="dispatch-pending-card">
                        <div>
                            <strong>{{ $pendingRequest->referenceCode() }}</strong>
                            <p>{{ $pendingRequest->client?->name ?? 'Sin cliente' }} · {{ number_format($pendingRequest->requestedPalletsCount(), 0, ',', '.') }} pallets</p>
                        </div>
                        <div class="inline-actions action-buttons">
                            <span class="status-badge merchandise-request-status merchandise-request-status--{{ $pendingRequest->status }}">{{ $pendingRequest->statusLabel() }}</span>
                            <a href="{{ route('dispatches.requests.show', $pendingRequest) }}" class="button-secondary compact-button btn-table">Ver pedido</a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="surface-card stock-table-shell compact-card">
        <div class="ops-section-heading">
            <div>
                <strong>Salidas recientes</strong>
                <p class="merchandise-request-summary-copy">Historial reciente de expediciones manuales o generadas desde solicitud.</p>
            </div>
        </div>

        <div class="data-table-wrap">
            <table class="data-table table-compact" aria-label="Listado de salidas">
                <thead>
                    <tr>
                        <th>Salida</th>
                        <th>Cliente</th>
                        <th>Tipo</th>
                        <th>Estado</th>
                        <th>Total pallets</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($dispatches as $dispatch)
                        <tr>
                            <td><strong>{{ $dispatch->dispatchNumber() }}</strong></td>
                            <td>{{ $dispatch->client?->name ?? 'Sin cliente' }}</td>
                            <td>{{ $dispatch->type === \App\Models\GoodsDispatch::TYPE_REQUEST ? 'Desde solicitud' : 'Manual' }}</td>
                            <td><span class="status-badge dispatch-status dispatch-status--{{ $dispatch->status }}">{{ $dispatch->statusLabel() }}</span></td>
                            <td>{{ number_format($dispatch->palletsCount(), 0, ',', '.') }}</td>
                            <td><a href="{{ route('dispatches.show', $dispatch) }}" class="button-secondary compact-button btn-table">Ver salida</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">Todavia no hay salidas registradas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($dispatches->hasPages())
        <div class="pagination-card surface-card compact-card">
            {{ $dispatches->links() }}
        </div>
    @endif
@endsection
