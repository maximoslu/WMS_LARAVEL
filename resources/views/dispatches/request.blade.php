@extends('layouts.dashboard')

@section('title', 'Gestión de pedido | MAXIMO WMS')
@section('topbar_title', 'Gestión de pedido')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Salidas', 'href' => route('dispatches.index')],
            ['label' => 'Pedidos pendientes', 'href' => route('dispatches.requests.index')],
            ['label' => $merchandiseRequest->referenceCode()],
        ];
        $requestedPallets = $merchandiseRequest->requestedPalletsCount();
        $requestedPeaks = $merchandiseRequest->requestedPeaksCount();
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (session('warning'))
        <div class="alert alert-error">{{ session('warning') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <section class="surface-card compact-card wms-flow-hero">
        <div class="wms-flow-hero-copy">
            <span class="status-chip">Pedido a preparar</span>
            <h2 class="ops-page-title page-title-compact">{{ $merchandiseRequest->referenceCode() }}</h2>
            <p>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }} · {{ $merchandiseRequest->requestedBy?->name ?? 'Sin usuario' }}</p>
        </div>
        <div class="wms-flow-hero-side">
            <span class="status-badge merchandise-request-status merchandise-request-status--{{ $merchandiseRequest->status }}">
                {{ $merchandiseRequest->statusLabel() }}
            </span>
        </div>
    </section>

    <section class="wms-detail-grid">
        <article class="surface-card compact-card wms-flow-card">
            <div class="wms-section-head">
                <div>
                    <strong>Resumen de operativa</strong>
                    <p class="merchandise-request-summary-copy">El almacén ve enseguida qué ha pedido el cliente y qué volumen tiene delante.</p>
                </div>
            </div>

            <div class="wms-summary-kpis">
                <div class="wms-kpi-tile">
                    <span>Fecha</span>
                    <strong>{{ $merchandiseRequest->submittedAt()?->format('d/m/Y H:i') }}</strong>
                </div>
                <div class="wms-kpi-tile">
                    <span>Pallets</span>
                    <strong>{{ number_format($requestedPallets, 0, ',', '.') }}</strong>
                </div>
                <div class="wms-kpi-tile">
                    <span>Picos</span>
                    <strong>{{ number_format($requestedPeaks, 0, ',', '.') }}</strong>
                </div>
                <div class="wms-kpi-tile">
                    <span>Salida</span>
                    <strong>{{ $merchandiseRequest->dispatch?->dispatchNumber() ?? 'Pendiente' }}</strong>
                </div>
            </div>
        </article>

        <article class="surface-card compact-card wms-flow-card">
            <div class="wms-section-head">
                <div>
                    <strong>Siguiente acción</strong>
                    <p class="merchandise-request-summary-copy">Zona de decisión rápida para que el almacén no tenga que interpretar la pantalla.</p>
                </div>
            </div>

            <div class="wms-action-grid">
                <form method="POST" action="{{ route('merchandise-requests.update-status', $merchandiseRequest) }}" class="wms-action-card">
                    @csrf
                    @method('PATCH')
                    <strong>Estado del pedido</strong>
                    <p>Actualiza la fase de trabajo de forma directa.</p>

                    <label class="auth-field">
                        <span>Nuevo estado</span>
                        <select name="status" class="auth-input">
                            @foreach (\App\Models\MerchandiseRequest::statusOptions() as $status => $label)
                                <option value="{{ $status }}" @selected($merchandiseRequest->status === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <button type="submit" class="button-primary compact-button btn-compact">Cambiar estado</button>
                </form>

                <div class="wms-action-card">
                    <strong>Documentación y salida</strong>
                    <p>Imprime la hoja de preparación o genera la salida definitiva.</p>

                    <a href="{{ route('merchandise-requests.preparation-pdf', $merchandiseRequest) }}" class="button-secondary compact-button btn-compact wms-button-with-icon" target="_blank" rel="noopener noreferrer">
                        <span class="wms-button-icon" aria-hidden="true"><x-module-icon name="printer" /></span>
                        Imprimir preparación
                    </a>

                    @if ($merchandiseRequest->dispatch)
                        <a href="{{ route('dispatches.show', $merchandiseRequest->dispatch) }}" class="button-secondary compact-button btn-compact">Ver salida</a>

                        @if (in_array($merchandiseRequest->status, [\App\Models\MerchandiseRequest::STATUS_SENT, \App\Models\MerchandiseRequest::STATUS_COMPLETED], true))
                            <a href="{{ route('dispatches.delivery-note', $merchandiseRequest->dispatch) }}" class="button-primary compact-button btn-compact wms-button-with-icon" target="_blank" rel="noopener noreferrer">
                                <span class="wms-button-icon" aria-hidden="true"><x-module-icon name="printer" /></span>
                                Imprimir albarán
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
        </article>
    </section>

    <section class="surface-card compact-card wms-flow-card">
        <div class="wms-section-head">
            <div>
                <strong>Líneas del pedido</strong>
                <p class="merchandise-request-summary-copy">Cada línea refleja claramente qué se pidió y qué se ha terminado cargando.</p>
            </div>
            <span class="ops-page-meta">{{ $merchandiseRequest->lines->count() }} líneas</span>
        </div>

        <div class="wms-line-card-list">
            @foreach ($merchandiseRequest->lines as $line)
                @php
                    $dispatchLine = $merchandiseRequest->dispatch?->lines->first(
                        fn ($candidate) => (int) $candidate->source_request_line_id === (int) $line->id
                    ) ?? $merchandiseRequest->dispatch?->lines->first(
                        fn ($candidate) => ! $candidate->is_extra_line && (int) $candidate->item_id === (int) $line->item_id
                            && (string) $candidate->line_type === (string) $line->line_type
                            && (int) ($candidate->stock_peak_index ?? 0) === (int) ($line->stock_peak_index ?? 0)
                    );
                @endphp
                <article class="wms-line-card">
                    <div class="wms-line-card-head">
                        <div>
                            <strong>{{ $line->item?->sku ?? 'Articulo eliminado' }}</strong>
                            <p>{{ $line->item?->description ?? 'Sin descripción' }}</p>
                        </div>
                        <span class="wms-line-type-pill wms-line-type-pill--{{ $line->lineType() }}">{{ $line->lineTypeLabel() }}</span>
                    </div>

                    <div class="wms-line-card-meta">
                        <span>Solicitado {{ $line->requestedQuantityLabel() }}</span>
                        <span>{{ $line->unitsLabel() }}</span>
                        <span>{{ $line->lot ? 'Lote '.$line->lot : 'Sin lote' }}</span>
                        @if ($line->stockPallet?->location_text)
                            <span>Ubicación {{ $line->stockPallet->location_text }}</span>
                        @endif
                        @if ($dispatchLine)
                            <span>Cargado {{ $dispatchLine->loadedQuantityLabel() }}</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    @if ($merchandiseRequest->dispatch && $merchandiseRequest->dispatch->lines->contains(fn ($line) => $line->is_extra_line))
        <section class="surface-card compact-card wms-flow-card">
            <div class="wms-section-head">
                <div>
                    <strong>Líneas añadidas en carga real</strong>
                    <p class="merchandise-request-summary-copy">Sustituciones o referencias extra registradas durante la expedición.</p>
                </div>
            </div>

            <div class="wms-line-card-list">
                @foreach ($merchandiseRequest->dispatch->lines->where('is_extra_line', true) as $extraLine)
                    <article class="wms-line-card">
                        <div class="wms-line-card-head">
                            <div>
                                <strong>{{ $extraLine->sku }}</strong>
                                <p>{{ $extraLine->description }}</p>
                            </div>
                            <div class="wms-line-card-badges">
                                <span class="wms-line-type-pill wms-line-type-pill--{{ $extraLine->lineType() }}">{{ $extraLine->lineTypeLabel() }}</span>
                                <span class="ops-status">Extra</span>
                            </div>
                        </div>

                        <div class="wms-line-card-meta">
                            <span>{{ $extraLine->loadedQuantityLabel() }}</span>
                            <span>{{ $extraLine->unitsLabel() }}</span>
                            <span>{{ $extraLine->lot ? 'Lote '.$extraLine->lot : 'Sin lote' }}</span>
                            <span>{{ $extraLine->loading_notes ?: 'Sin observaciones' }}</span>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
@endsection
