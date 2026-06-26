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

    @if ($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
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
                <dd>{{ $merchandiseRequest->submittedAt()?->format('d/m/Y H:i') }}</dd>
            </div>
            <div>
                <dt>Total pallets</dt>
                <dd>{{ number_format($merchandiseRequest->requestedPalletsCount(), 0, ',', '.') }}</dd>
            </div>
            @if ($merchandiseRequest->dispatch)
                <div>
                    <dt>Salida asociada</dt>
                    <dd>{{ $merchandiseRequest->dispatch->dispatchNumber() }}</dd>
                </div>
            @endif
        </dl>
    </section>

    <section class="surface-card compact-card merchandise-request-detail-card">
        <div class="ops-section-heading">
            <div>
                <strong>Seguimiento del pedido</strong>
                <p class="merchandise-request-summary-copy">Estado actual e hitos visibles para el cliente y el equipo interno.</p>
            </div>
        </div>

        <div class="dispatch-history-grid">
            <article class="dispatch-history-item">
                <span class="ops-status badge-compact">Creado</span>
                <strong>{{ $merchandiseRequest->submittedAt()?->format('d/m/Y H:i') }}</strong>
                <p>Solicitud registrada en el sistema.</p>
            </article>

            <article class="dispatch-history-item">
                <span class="ops-status badge-compact">Enviado</span>
                <strong>{{ $merchandiseRequest->shipped_at?->format('d/m/Y H:i') ?: 'Pendiente' }}</strong>
                <p>Salida expedida o pendiente de expedicion.</p>
            </article>

            <article class="dispatch-history-item">
                <span class="ops-status badge-compact">Completado</span>
                <strong>{{ $merchandiseRequest->completed_at?->format('d/m/Y H:i') ?: $merchandiseRequest->updated_at?->format('d/m/Y H:i') }}</strong>
                <p>{{ $merchandiseRequest->completed_at ? 'Pedido cerrado.' : 'Ultima actualizacion registrada.' }}</p>
            </article>
        </div>
    </section>

    @unless ($isClient)
        <section class="surface-card compact-card merchandise-request-detail-card">
            <div class="ops-section-heading">
                <div>
                    <strong>Gestion operativa</strong>
                    <p class="merchandise-request-summary-copy">Cambia el estado, imprime preparacion o genera la salida documental.</p>
                </div>
            </div>

            <div class="dispatch-actions-grid">
                <form method="POST" action="{{ route('merchandise-requests.update-status', $merchandiseRequest) }}" class="dispatch-inline-form">
                    @csrf
                    @method('PATCH')

                    <label class="auth-field">
                        <span>Cambiar estado</span>
                        <select name="status" class="auth-input">
                            @foreach (\App\Models\MerchandiseRequest::statusOptions() as $status => $label)
                                <option value="{{ $status }}" @selected($merchandiseRequest->status === $status)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <button type="submit" class="button-primary compact-button btn-compact">Guardar estado</button>
                </form>

                <div class="dispatch-action-stack">
                    <a href="{{ route('merchandise-requests.preparation-pdf', $merchandiseRequest) }}" class="button-secondary compact-button btn-compact" target="_blank" rel="noopener noreferrer">
                        Imprimir preparacion
                    </a>

                    @if ($merchandiseRequest->dispatch)
                        <a href="{{ route('dispatches.show', $merchandiseRequest->dispatch) }}" class="button-secondary compact-button btn-compact">
                            Ver salida
                        </a>

                        @if (in_array($merchandiseRequest->status, [\App\Models\MerchandiseRequest::STATUS_SENT, \App\Models\MerchandiseRequest::STATUS_COMPLETED], true))
                            <a href="{{ route('dispatches.delivery-note', $merchandiseRequest->dispatch) }}" class="button-secondary compact-button btn-compact" target="_blank" rel="noopener noreferrer">
                                Imprimir albaran
                            </a>
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
    @endunless

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
                        @if (! $isClient && $merchandiseRequest->dispatch)
                            <th>Pallets cargados</th>
                        @endif
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
                            @if (! $isClient && $merchandiseRequest->dispatch)
                                @php($dispatchLine = $merchandiseRequest->dispatch->lines->firstWhere('item_id', $line->item_id))
                                <td>{{ number_format($dispatchLine?->loadedPallets() ?? $line->requested_pallets, 0, ',', '.') }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
