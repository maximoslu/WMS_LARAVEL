@extends('layouts.dashboard')

@section('title', 'Pedidos pendientes | MAXIMO WMS')
@section('topbar_title', 'Pedidos pendientes')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <a href="{{ route('dispatches.index') }}">Salidas</a>
        <span>/</span>
        <span>Pedidos pendientes</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Pedidos pendientes</h2>
            <span class="ops-page-meta">{{ $requests->total() }} solicitudes</span>
        </div>
    </section>

    <section class="surface-card stock-table-shell compact-card">
        <div class="data-table-wrap">
            <table class="data-table table-compact" aria-label="Pedidos pendientes">
                <thead>
                    <tr>
                        <th>Solicitud</th>
                        <th>Cliente</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Total pallets</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requests as $merchandiseRequest)
                        <tr>
                            <td><strong>{{ $merchandiseRequest->referenceCode() }}</strong></td>
                            <td>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</td>
                            <td>{{ $merchandiseRequest->submittedAt()?->format('d/m/Y H:i') }}</td>
                            <td><span class="status-badge merchandise-request-status merchandise-request-status--{{ $merchandiseRequest->status }}">{{ $merchandiseRequest->statusLabel() }}</span></td>
                            <td>{{ number_format($merchandiseRequest->requestedPalletsCount(), 0, ',', '.') }}</td>
                            <td>
                                <div class="inline-actions action-buttons">
                                    <a href="{{ route('dispatches.requests.show', $merchandiseRequest) }}" class="button-secondary compact-button btn-table">Ver pedido</a>
                                    @if ($merchandiseRequest->dispatch)
                                        <a href="{{ route('dispatches.show', $merchandiseRequest->dispatch) }}" class="button-secondary compact-button btn-table">Ver salida</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">No hay pedidos pendientes.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($requests->hasPages())
        <div class="pagination-card surface-card compact-card">
            {{ $requests->links() }}
        </div>
    @endif
@endsection
