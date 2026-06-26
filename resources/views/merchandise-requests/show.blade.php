@extends('layouts.dashboard')

@section('title', 'Detalle de solicitud | MAXIMO WMS')
@section('topbar_title', 'Detalle de solicitud')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <a href="{{ route('merchandise-requests.index') }}">Solicitudes de mercancia</a>
        <span>/</span>
        <span>{{ $merchandiseRequest->referenceCode() }}</span>
    </nav>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card compact-card merchandise-request-detail-card">
        <div class="ops-page-headline">
            <div>
                <h2 class="ops-page-title page-title-compact">{{ $merchandiseRequest->referenceCode() }}</h2>
                <p class="access-request-detail-meta">
                    {{ $isClient ? 'Tu pedido registrado' : 'Solicitud registrada en el sistema' }}
                </p>
            </div>
            <span class="status-badge merchandise-request-status merchandise-request-status--{{ $merchandiseRequest->status }}">
                {{ $merchandiseRequest->statusLabel() }}
            </span>
        </div>

        <dl class="access-request-detail-grid">
            <div>
                <dt>Cliente</dt>
                <dd>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</dd>
            </div>
            <div>
                <dt>Solicitante</dt>
                <dd>{{ $merchandiseRequest->requestedBy?->name ?? 'Sin usuario' }}</dd>
            </div>
            <div>
                <dt>Fecha envio</dt>
                <dd>{{ $merchandiseRequest->submittedAt()?->format('Y-m-d H:i') }}</dd>
            </div>
            <div>
                <dt>Total pallets</dt>
                <dd>{{ number_format($merchandiseRequest->requestedPalletsCount(), 0, ',', '.') }}</dd>
            </div>
        </dl>
    </section>

    <section class="surface-card stock-table-shell compact-card">
        <div class="ops-section-heading">
            <strong>Lineas del pedido</strong>
            <span class="ops-page-meta">{{ $merchandiseRequest->lines->count() }} lineas</span>
        </div>

        <div class="data-table-wrap">
            <table class="data-table table-compact" aria-label="Lineas de solicitud de mercancia">
                <thead>
                    <tr>
                        <th>Mercancia</th>
                        <th>Descripcion</th>
                        <th>Lote</th>
                        <th>Uds/pallet</th>
                        <th>Pallets solicitados</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($merchandiseRequest->lines as $line)
                        <tr>
                            <td><strong>{{ $line->item?->sku ?? 'Articulo eliminado' }}</strong></td>
                            <td>{{ $line->item?->description ?? 'Sin descripcion disponible' }}</td>
                            <td>{{ $line->lot ?: 'Sin lote' }}</td>
                            <td>{{ number_format($line->units_per_pallet, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->requested_pallets, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
