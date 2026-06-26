@extends('layouts.dashboard')

@section('title', 'Gestion de pedido | MAXIMO WMS')
@section('topbar_title', 'Gestion de pedido')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <a href="{{ route('dispatches.index') }}">Salidas</a>
        <span>/</span>
        <a href="{{ route('dispatches.requests.index') }}">Pedidos pendientes</a>
        <span>/</span>
        <span>{{ $merchandiseRequest->referenceCode() }}</span>
    </nav>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (session('warning'))
        <div class="alert alert-error">{{ session('warning') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <section class="surface-card compact-card merchandise-request-detail-card">
        <div class="ops-page-headline">
            <div>
                <h2 class="ops-page-title page-title-compact">{{ $merchandiseRequest->referenceCode() }}</h2>
                <p class="access-request-detail-meta">{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</p>
            </div>
            <span class="status-badge merchandise-request-status merchandise-request-status--{{ $merchandiseRequest->status }}">
                {{ $merchandiseRequest->statusLabel() }}
            </span>
        </div>

        <dl class="access-request-detail-grid">
            <div>
                <dt>Solicitante</dt>
                <dd>{{ $merchandiseRequest->requestedBy?->name ?? 'Sin usuario' }}</dd>
            </div>
            <div>
                <dt>Fecha</dt>
                <dd>{{ $merchandiseRequest->submittedAt()?->format('d/m/Y H:i') }}</dd>
            </div>
            <div>
                <dt>Total pallets</dt>
                <dd>{{ number_format($merchandiseRequest->requestedPalletsCount(), 0, ',', '.') }}</dd>
            </div>
            <div>
                <dt>Salida asociada</dt>
                <dd>{{ $merchandiseRequest->dispatch?->dispatchNumber() ?? 'Pendiente de generar' }}</dd>
            </div>
        </dl>
    </section>

    <section class="surface-card compact-card merchandise-request-detail-card">
        <div class="dispatch-actions-grid">
            <form method="POST" action="{{ route('merchandise-requests.update-status', $merchandiseRequest) }}" class="dispatch-inline-form">
                @csrf
                @method('PATCH')
                <label class="auth-field">
                    <span>Cambiar estado del pedido</span>
                    <select name="status" class="auth-input">
                        @foreach (\App\Models\MerchandiseRequest::statusOptions() as $status => $label)
                            <option value="{{ $status }}" @selected($merchandiseRequest->status === $status)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="button-primary compact-button btn-compact">Cambiar estado</button>
            </form>

            <div class="dispatch-action-stack">
                <a href="{{ route('merchandise-requests.preparation-pdf', $merchandiseRequest) }}" class="button-secondary compact-button btn-compact" target="_blank" rel="noopener noreferrer">Imprimir preparacion</a>

                @if ($merchandiseRequest->dispatch)
                    <a href="{{ route('dispatches.show', $merchandiseRequest->dispatch) }}" class="button-secondary compact-button btn-compact">Ver salida</a>

                    @if (in_array($merchandiseRequest->status, [\App\Models\MerchandiseRequest::STATUS_SENT, \App\Models\MerchandiseRequest::STATUS_COMPLETED], true))
                        <a href="{{ route('dispatches.delivery-note', $merchandiseRequest->dispatch) }}" class="button-secondary compact-button btn-compact" target="_blank" rel="noopener noreferrer">Imprimir albaran</a>
                    @endif
                @else
                    <form method="POST" action="{{ route('dispatches.requests.generate', $merchandiseRequest) }}">
                        @csrf
                        <button type="submit" class="button-primary compact-button btn-compact">Generar salida</button>
                    </form>
                @endif
            </div>
        </div>
    </section>

    <section class="surface-card stock-table-shell compact-card">
        <div class="ops-section-heading">
            <strong>Lineas del pedido</strong>
            <span class="ops-page-meta">{{ $merchandiseRequest->lines->count() }} lineas</span>
        </div>

        <div class="data-table-wrap">
            <table class="data-table table-compact">
                <thead>
                    <tr>
                        <th>Mercancia</th>
                <th>Descripcion</th>
                <th>Lote</th>
                <th>Uds/pallet</th>
                <th>Pallets solicitados</th>
                @if ($merchandiseRequest->dispatch)
                    <th>Pallets cargados</th>
                @endif
            </tr>
        </thead>
        <tbody>
                    @foreach ($merchandiseRequest->lines as $line)
                        @php
                            $dispatchLine = $merchandiseRequest->dispatch?->lines->first(
                                fn ($candidate) => (int) $candidate->source_request_line_id === (int) $line->id
                            ) ?? $merchandiseRequest->dispatch?->lines->first(
                                fn ($candidate) => ! $candidate->is_extra_line && (int) $candidate->item_id === (int) $line->item_id
                            );
                        @endphp
                        <tr>
                            <td><strong>{{ $line->item?->sku ?? 'Articulo eliminado' }}</strong></td>
                        <td>{{ $line->item?->description ?? 'Sin descripcion' }}</td>
                        <td>{{ $line->lot ?: 'Sin lote' }}</td>
                        <td>{{ number_format($line->units_per_pallet, 0, ',', '.') }}</td>
                        <td>{{ number_format($line->requested_pallets, 0, ',', '.') }}</td>
                        @if ($merchandiseRequest->dispatch)
                            <td>{{ number_format($dispatchLine?->loadedPallets() ?? $line->requested_pallets, 0, ',', '.') }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            </table>
        </div>
    </section>

    @if ($merchandiseRequest->dispatch && $merchandiseRequest->dispatch->lines->contains(fn ($line) => $line->is_extra_line))
        <section class="surface-card stock-table-shell compact-card">
            <div class="ops-section-heading">
                <strong>Carga real adicional</strong>
                <span class="ops-page-meta">
                    {{ $merchandiseRequest->dispatch->lines->where('is_extra_line', true)->count() }} lineas extra
                </span>
            </div>

            <div class="data-table-wrap">
                <table class="data-table table-compact">
                    <thead>
                        <tr>
                            <th>Mercancia</th>
                            <th>Descripcion</th>
                            <th>Lote</th>
                            <th>Pallets cargados</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($merchandiseRequest->dispatch->lines->where('is_extra_line', true) as $extraLine)
                            <tr>
                                <td><strong>{{ $extraLine->sku }}</strong></td>
                                <td>{{ $extraLine->description }}</td>
                                <td>{{ $extraLine->lot ?: 'Sin lote' }}</td>
                                <td>{{ number_format($extraLine->loadedPallets(), 0, ',', '.') }}</td>
                                <td>{{ $extraLine->loading_notes ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
