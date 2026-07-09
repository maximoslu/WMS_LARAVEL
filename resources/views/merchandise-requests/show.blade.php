@extends('layouts.dashboard')

@section('title', 'Pedido '.$merchandiseRequest->referenceCode().' | MAXIMO WMS')
@section('topbar_title', 'PEDIDO')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'PEDIDOS', 'href' => route('merchandise-requests.index')],
            ['label' => $merchandiseRequest->referenceCode()],
        ];
        $requestedPallets = $merchandiseRequest->requestedPalletsCount();
        $requestedPeaks = $merchandiseRequest->requestedPeaksCount();
        $requestedUnits = (int) $merchandiseRequest->lines->sum('requested_units');
        $timeline = [
            [
                'label' => 'Registrado',
                'date' => $merchandiseRequest->submittedAt(),
                'description' => '',
            ],
            [
                'label' => 'Preparación',
                'date' => $merchandiseRequest->prepared_at,
                'description' => '',
            ],
            [
                'label' => 'Enviado',
                'date' => $merchandiseRequest->shipped_at,
                'description' => '',
            ],
            [
                'label' => 'Completado',
                'date' => $merchandiseRequest->completed_at,
                'description' => '',
            ],
        ];
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <section class="surface-card compact-card wms-flow-hero">
        <div class="wms-flow-hero-copy">
            <span class="status-chip">Pedido {{ $isClient ? 'cliente' : 'interno' }}</span>
            <h2 class="ops-page-title page-title-compact">{{ $merchandiseRequest->referenceCode() }}</h2>
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
                    <strong>Datos</strong>
                </div>
            </div>

            <div class="wms-summary-kpis">
                <div class="wms-kpi-tile">
                    <span>Cliente</span>
                    <strong>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</strong>
                </div>
                <div class="wms-kpi-tile">
                    <span>Solicitante</span>
                    <strong>{{ $merchandiseRequest->requestedBy?->name ?? 'Sin usuario' }}</strong>
                </div>
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
                    <span>Unidades</span>
                    <strong>{{ number_format($requestedUnits, 0, ',', '.') }}</strong>
                </div>
            </div>
        </article>

        <article class="surface-card compact-card wms-flow-card">
            <div class="wms-section-head">
                <div>
                    <strong>Seguimiento</strong>
                    <p class="merchandise-request-summary-copy">Hitos visibles y fáciles de leer para cliente y equipo interno.</p>
                </div>
            </div>

            <div class="wms-timeline">
                @foreach ($timeline as $step)
                    <article class="wms-timeline-step {{ $step['date'] ? 'is-complete' : 'is-pending' }}">
                        <span class="wms-timeline-dot" aria-hidden="true"></span>
                        <div>
                            <strong>{{ $step['label'] }}</strong>
                            @if ($step['description'])
                                <p>{{ $step['description'] }}</p>
                            @endif
                        </div>
                        <span>{{ $step['date']?->format('d/m/Y H:i') ?: 'Pendiente' }}</span>
                    </article>
                @endforeach
            </div>
        </article>
    </section>

    @unless ($isClient)
        <section class="surface-card compact-card wms-flow-card">
            <div class="wms-section-head">
                <div>
                    <strong>Acciones</strong>
                </div>
            </div>

            <div class="wms-action-grid">
                <form method="POST" action="{{ route('merchandise-requests.update-status', $merchandiseRequest) }}" class="wms-action-card">
                    @csrf
                    @method('PATCH')

                    <strong>Cambiar estado</strong>

                    <label class="auth-field">
                        <span>Nuevo estado</span>
                        <select name="status" class="auth-input">
                            @foreach (\App\Models\MerchandiseRequest::statusOptions() as $status => $label)
                                <option value="{{ $status }}" @selected($merchandiseRequest->status === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <button type="submit" class="button-primary compact-button btn-compact">Guardar estado</button>
                </form>

                <div class="wms-action-card">
                    <strong>Documentos y salida</strong>

                    <a href="{{ route('merchandise-requests.preparation-pdf', $merchandiseRequest) }}" class="button-secondary compact-button btn-compact wms-button-with-icon" target="_blank" rel="noopener noreferrer">
                        <span class="wms-button-icon" aria-hidden="true"><x-module-icon name="printer" /></span>
                        Imprimir preparación
                    </a>

                    @if ($merchandiseRequest->dispatch)
                        <a href="{{ route('dispatches.show', $merchandiseRequest->dispatch) }}" class="button-secondary compact-button btn-compact">
                            Ver salida asociada
                        </a>

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
        </section>
    @endunless

    <section class="surface-card compact-card wms-flow-card">
        <div class="wms-section-head">
            <div>
                <strong>Líneas</strong>
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
                            <p>{{ $line->item?->description ?? 'Sin descripción disponible' }}</p>
                        </div>
                        <span class="wms-line-type-pill wms-line-type-pill--{{ $line->lineType() }}">{{ $line->lineTypeLabel() }}</span>
                    </div>

                    <div class="wms-line-card-meta">
                        <span>{{ $line->requestedQuantityLabel() }}</span>
                        <span>{{ $line->unitsLabel() }}</span>
                        <span>{{ $line->lot ? 'Lote '.$line->lot : 'Sin lote' }}</span>
                        @if ($line->stockPallet?->location_text)
                            <span>Ubicación {{ $line->stockPallet->location_text }}</span>
                        @endif
                        @if (! $isClient && $dispatchLine)
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
                    <strong>Carga real adicional</strong>
                </div>
                <span class="ops-page-meta">{{ $merchandiseRequest->dispatch->lines->where('is_extra_line', true)->count() }} líneas</span>
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
