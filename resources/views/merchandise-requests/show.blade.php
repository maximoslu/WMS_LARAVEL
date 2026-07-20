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
        $dispatch = $merchandiseRequest->dispatch;
        $requestedPallets = $merchandiseRequest->requestedPalletsCount();
        $requestedPeaks = $merchandiseRequest->requestedPeaksCount();
        $requestedUnits = (int) $merchandiseRequest->lines->sum('requested_units');
        $lineCount = $merchandiseRequest->lines->count();
        $timeline = [
            ['label' => 'Registrado', 'date' => $merchandiseRequest->submittedAt()],
            ['label' => 'Preparación', 'date' => $merchandiseRequest->prepared_at],
            ['label' => 'Enviado', 'date' => $merchandiseRequest->shipped_at],
            ['label' => 'Completado', 'date' => $merchandiseRequest->completed_at],
        ];
        $currentStepIndex = null;
        foreach ($timeline as $stepIndex => $step) {
            if (! $step['date']) {
                $currentStepIndex = $stepIndex;
                break;
            }
        }
        $canStartLoading = ! $isClient && $dispatch === null && in_array($merchandiseRequest->status, [
            \App\Models\MerchandiseRequest::STATUS_PENDING,
            \App\Models\MerchandiseRequest::STATUS_PREPARING,
        ], true);
        $canContinueLoading = ! $isClient && $dispatch !== null && in_array($dispatch->status, [
            \App\Models\GoodsDispatch::STATUS_DRAFT,
            \App\Models\GoodsDispatch::STATUS_PREPARING,
        ], true);
        $primaryLoadingLabel = $canStartLoading
            ? 'Empezar carga'
            : ($canContinueLoading ? 'Continuar carga' : 'Ver carga');
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="order-detail wms-detail-page">
        @if (session('status'))
            <div class="order-alert order-alert--success" role="status">
                <span class="order-alert-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="18" height="18"><path d="M7.5 10.5l1.8 1.8 3.5-4.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10" cy="10" r="7.2" stroke="currentColor" stroke-width="1.6"/></svg>
                </span>
                <div class="order-alert-copy"><p>{{ session('status') }}</p></div>
            </div>
        @endif

        @if (session('scheduleWarning'))
            <div class="order-alert order-alert--warning" role="alert">
                <span class="order-alert-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="18" height="18"><path d="M10 2.8l7.2 12.6H2.8L10 2.8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 8.2v3.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="13.6" r="0.9" fill="currentColor"/></svg>
                </span>
                <div class="order-alert-copy">
                    <strong>Pedido fuera de horario operativo</strong>
                    <p>Lo tramitaremos con la mayor diligencia posible, pero no podemos garantizar su preparación o expedición para el siguiente día hábil.</p>
                </div>
            </div>
        @elseif (session('warning'))
            <div class="order-alert order-alert--warning" role="alert">
                <span class="order-alert-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="18" height="18"><path d="M10 2.8l7.2 12.6H2.8L10 2.8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 8.2v3.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="13.6" r="0.9" fill="currentColor"/></svg>
                </span>
                <div class="order-alert-copy"><p>{{ session('warning') }}</p></div>
            </div>
        @endif

        @if ($errors->any())
            <div class="order-alert order-alert--error" role="alert">
                <div class="order-alert-copy"><p>{{ $errors->first() }}</p></div>
            </div>
        @endif

        <section class="surface-card compact-card order-header wms-detail-header">
            <div class="wms-detail-header-main">
                <div class="order-header-main">
                    <span class="order-type-chip">Pedido {{ $isClient ? 'cliente' : 'interno' }}</span>
                    <h2 class="order-code">{{ $merchandiseRequest->referenceCode() }}</h2>
                    <span class="status-badge merchandise-request-status merchandise-request-status--{{ $merchandiseRequest->status }}">
                        {{ $merchandiseRequest->statusLabel() }}
                    </span>
                </div>

                <div class="wms-detail-context">
                    @if ($dispatch)
                        <span>Salida asociada</span>
                        <strong>{{ $dispatch->dispatchNumber() }}</strong>
                        <em>{{ $dispatch->statusLabel() }}</em>
                    @else
                        <span>Salida asociada</span>
                        <strong>Sin salida</strong>
                        <em>{{ $isClient ? 'Pendiente de preparacion' : 'Pendiente de generar' }}</em>
                    @endif
                </div>
            </div>

            <dl class="order-meta wms-detail-meta">
                <div class="order-meta-item">
                    <dt>Cliente</dt>
                    <dd>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</dd>
                </div>
                <div class="order-meta-item">
                    <dt>Solicitante</dt>
                    <dd>{{ $merchandiseRequest->requestedBy?->name ?? 'Sin usuario' }}</dd>
                </div>
                <div class="order-meta-item">
                    <dt>Fecha</dt>
                    <dd>{{ $merchandiseRequest->submittedAt()?->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                <div class="order-meta-item">
                    <dt>Lineas</dt>
                    <dd>{{ number_format($lineCount, 0, ',', '.') }}</dd>
                </div>
                <div class="order-meta-item">
                    <dt>Pallets</dt>
                    <dd>{{ number_format($requestedPallets, 0, ',', '.') }}</dd>
                </div>
                <div class="order-meta-item">
                    <dt>Picos</dt>
                    <dd>{{ number_format($requestedPeaks, 0, ',', '.') }}</dd>
                </div>
                <div class="order-meta-item">
                    <dt>Unidades</dt>
                    <dd>{{ number_format($requestedUnits, 0, ',', '.') }}</dd>
                </div>
            </dl>

            @unless ($isClient)
                <div class="wms-detail-actions order-primary-action">
                    @if ($dispatch?->status === \App\Models\GoodsDispatch::STATUS_SENT)
                        <form method="POST" action="{{ route('merchandise-requests.update-status', $merchandiseRequest) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ \App\Models\MerchandiseRequest::STATUS_COMPLETED }}">
                            <button type="submit" class="button-primary compact-button btn-compact" onclick="return confirm('¿Marcar este pedido como completado?')">
                                Marcar como completado
                            </button>
                        </form>
                    @elseif ($canStartLoading)
                        <form method="POST" action="{{ route('dispatches.requests.generate', $merchandiseRequest) }}">
                            @csrf
                            <input type="hidden" name="return_to_request" value="1">
                            <button type="submit" class="button-primary compact-button btn-compact">{{ $primaryLoadingLabel }}</button>
                        </form>
                    @elseif ($dispatch)
                        <a href="{{ route('dispatches.requests.show', $merchandiseRequest) }}" class="button-primary compact-button btn-compact">{{ $primaryLoadingLabel }}</a>
                    @endif

                    @if ($dispatch)
                        <a href="{{ route('dispatches.requests.show', $merchandiseRequest) }}" class="button-secondary compact-button btn-compact">Ver salida</a>
                    @endif
                </div>
            @endunless
        </section>

        <section class="surface-card compact-card order-track" aria-label="Seguimiento del pedido">
            <ol class="order-steps">
                @foreach ($timeline as $step)
                    @php
                        $stepState = $step['date']
                            ? 'is-complete'
                            : ($loop->index === $currentStepIndex ? 'is-current' : 'is-pending');
                    @endphp
                    <li class="order-step {{ $stepState }}">
                        <span class="order-step-dot" aria-hidden="true"></span>
                        <span class="order-step-label">{{ $step['label'] }}</span>
                        <span class="order-step-date">{{ $step['date']?->format('d/m/Y H:i') ?: 'Pendiente' }}</span>
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="surface-card compact-card order-prep-card" data-order-preparation-section>
            <div class="order-prep-head">
                <div>
                    <strong>Preparación del pedido</strong>
                    <p>{{ $isClient ? 'Detalle de las líneas solicitadas.' : 'Líneas solicitadas y carga real asociada.' }}</p>
                </div>

                @unless ($isClient)
                    <div class="order-primary-action">
                        @if ($dispatch?->status === \App\Models\GoodsDispatch::STATUS_SENT)
                            <form method="POST" action="{{ route('merchandise-requests.update-status', $merchandiseRequest) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ \App\Models\MerchandiseRequest::STATUS_COMPLETED }}">
                                <button type="submit" class="button-primary compact-button btn-compact" onclick="return confirm('¿Marcar este pedido como completado?')">
                                    Marcar como completado
                                </button>
                            </form>
                        @elseif ($canStartLoading)
                            <form method="POST" action="{{ route('dispatches.requests.generate', $merchandiseRequest) }}">
                                @csrf
                                <input type="hidden" name="return_to_request" value="1">
                                <button type="submit" class="button-primary compact-button btn-compact">{{ $primaryLoadingLabel }}</button>
                            </form>
                        @elseif ($dispatch)
                            <a href="{{ route('dispatches.requests.show', $merchandiseRequest) }}" class="button-primary compact-button btn-compact">{{ $primaryLoadingLabel }}</a>
                        @endif
                    </div>
                @endunless
            </div>

            @if ($canAddInternalLine)
                <form
                    method="POST"
                    action="{{ route('merchandise-requests.lines.store', $merchandiseRequest) }}"
                    class="wms-add-order-line"
                    data-merchandise-request-form
                    data-search-endpoint="{{ $searchEndpoint }}"
                    data-client-id="{{ $merchandiseRequest->client_id }}"
                >
                    @csrf

                    <div class="wms-add-order-line-head">
                        <div>
                            <strong>Añadir línea al pedido</strong>
                            <p>No descuenta stock hasta la confirmación de carga.</p>
                        </div>
                        <div class="merchandise-request-totals-inline" aria-label="Resumen de nuevas líneas">
                            <span><strong data-request-summary-lines>0</strong> líneas</span>
                            <span><strong data-request-summary-pallets>0</strong> pallets</span>
                            <span><strong data-request-summary-peaks>0</strong> picos</span>
                        </div>
                    </div>

                    <section class="merchandise-request-entry-row wms-add-order-line-entry" aria-label="Nueva línea de pedido">
                        <label class="auth-field merchandise-request-reference-field">
                            <span>Referencia / SKU</span>
                            <div
                                class="ajax-autocomplete"
                                data-ajax-autocomplete
                                data-endpoint="{{ $searchEndpoint }}"
                                data-min-chars="2"
                                data-empty-message="Escribe 2 caracteres."
                                data-no-results-message="Sin resultados"
                                data-searching-message="Buscando..."
                                data-error-message="Error al buscar"
                                data-request-item-picker
                            >
                                <div class="ajax-autocomplete-control">
                                    <input
                                        type="text"
                                        class="auth-input"
                                        placeholder="Buscar referencia..."
                                        autocomplete="off"
                                        data-autocomplete-input
                                    >
                                    <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear hidden>Limpiar</button>
                                </div>
                                <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                                    <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe 2 caracteres.</div>
                                    <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                                </div>
                            </div>
                        </label>

                        <div hidden data-request-selection-preview></div>

                        <label class="auth-field wms-quantity-field merchandise-request-entry-quantity">
                            <span data-request-picker-label>Cantidad</span>
                            <input type="number" min="1" step="1" value="1" class="auth-input" data-request-picker-quantity>
                        </label>

                        <button type="button" class="button-secondary compact-button btn-compact merchandise-request-entry-add" data-request-add-selected>
                            Añadir línea
                        </button>

                        <p class="helper-text" data-request-search-feedback>Busca una referencia.</p>
                    </section>

                    <div class="merchandise-request-hidden-inputs" data-request-hidden-inputs></div>
                    <script type="application/json" data-request-selected-items>@json($selectedItems)</script>

                    <div class="merchandise-request-line-table-head wms-add-order-line-table-head" aria-hidden="true">
                        <span>Línea</span>
                        <span>Referencia</span>
                        <span>Pallets</span>
                        <span>Picos</span>
                        <span>Ubicación destino</span>
                        <span></span>
                    </div>

                    <div class="merchandise-request-summary-empty merchandise-request-summary-empty--compact" data-request-summary-empty>
                        Sin líneas nuevas.
                    </div>

                    <div class="merchandise-request-summary-list merchandise-request-line-list" data-request-summary-rows></div>

                    <div class="item-filter-actions action-buttons page-actions-compact wms-add-order-line-actions">
                        <button type="submit" class="button-primary compact-button btn-compact" data-request-submit disabled>
                            Añadir línea
                        </button>
                    </div>
                </form>
            @endif

            <div class="order-table-wrap">
                <table class="order-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Descripción</th>
                            <th>Lote</th>
                            <th>Ubicación destino</th>
                            <th>Cantidad</th>
                            <th>Uds/pallet</th>
                            <th>Tipo</th>
                            @unless ($isClient)
                                <th>Ubicación de recogida</th>
                                <th>Cargado</th>
                                <th>Estado carga</th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($merchandiseRequest->lines as $line)
                            @php
                                $dispatchLine = $dispatch?->lines->first(
                                    fn ($candidate) => (int) $candidate->source_request_line_id === (int) $line->id
                                ) ?? $dispatch?->lines->first(
                                    fn ($candidate) => ! $candidate->is_extra_line && (int) $candidate->item_id === (int) $line->item_id
                                        && (string) $candidate->line_type === (string) $line->line_type
                                        && (int) ($candidate->stock_peak_index ?? 0) === (int) ($line->stock_peak_index ?? 0)
                                );
                                $requestedLineUnits = $dispatchLine?->requestedUnitsTotal() ?? (int) ($line->requested_units ?? 0);
                                $loadedLineUnits = $dispatchLine?->loadedUnitsTotal() ?? 0;
                                $pickingLocationSummaries = collect();
                                if (! $isClient) {
                                    $pickingLocationSummaries = $dispatchLine?->pickingLocationSummaries() ?? collect();
                                    if ($pickingLocationSummaries->isEmpty() && $line->stockPallet !== null) {
                                        $pickingLocationSummaries = collect([[
                                            'location' => $line->stockPallet->pickingLocationLabel() ?? 'Sin ubicación registrada',
                                            'quantity' => null,
                                        ]]);
                                    }
                                }
                                $loadStateClass = 'pending';
                                $loadStateLabel = 'Pendiente de cargar';

                                if ($dispatchLine?->confirmed_at !== null || $loadedLineUnits > 0) {
                                    if ($requestedLineUnits > 0 && $loadedLineUnits > $requestedLineUnits) {
                                        $loadStateClass = 'difference';
                                        $loadStateLabel = 'Exceso';
                                    } elseif ($loadedLineUnits === $requestedLineUnits) {
                                        $loadStateClass = 'ok';
                                        $loadStateLabel = 'Completo';
                                    } elseif ($loadedLineUnits > 0) {
                                        $loadStateClass = 'partial';
                                        $loadStateLabel = 'Parcial';
                                    }
                                }
                            @endphp
                            <tr>
                                <td class="order-table-strong">{{ $line->item?->sku ?? 'Artículo eliminado' }}</td>
                                <td>{{ $line->item?->description ?? 'Sin descripción disponible' }}</td>
                                <td>{{ $line->lot ?: 'Sin lote' }}</td>
                                <td>{{ $line->destination_location ?: '-' }}</td>
                                <td>{{ $line->requestedQuantityLabel() }}</td>
                                <td>{{ $line->unitsLabel() }}</td>
                                <td><span class="wms-line-type-pill wms-line-type-pill--{{ $line->lineType() }}">{{ $line->lineTypeLabel() }}</span></td>
                                @unless ($isClient)
                                    <td>
                                        @forelse ($pickingLocationSummaries as $pickingSummary)
                                            <div><strong>{{ $pickingSummary['location'] }}</strong>{{ $pickingSummary['quantity'] ? ' · '.$pickingSummary['quantity'] : '' }}</div>
                                        @empty
                                            Pendiente de asignar ubicación
                                        @endforelse
                                    </td>
                                    <td>{{ $dispatchLine ? $dispatchLine->loadedQuantityLabel() : '—' }}</td>
                                    <td><span class="warehouse-load-state warehouse-load-state--{{ $loadStateClass }}">{{ $loadStateLabel }}</span></td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @unless ($isClient)
            <details class="surface-card compact-card order-secondary-actions" open>
                <summary>
                    <div>
                        <strong>Más acciones</strong>
                        <span>Estado, documentos y accesos secundarios</span>
                    </div>
                </summary>

                <div class="order-secondary-grid">
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

                        <button type="submit" class="button-secondary compact-button btn-compact">Guardar estado</button>
                    </form>

                    <div class="wms-action-card">
                        <strong>Documentos</strong>

                        <a href="{{ route('merchandise-requests.preparation-pdf', $merchandiseRequest) }}" class="button-secondary compact-button btn-compact wms-button-with-icon" target="_blank" rel="noopener noreferrer">
                            <span class="wms-button-icon" aria-hidden="true"><x-module-icon name="printer" /></span>
                            Imprimir preparación
                        </a>

                        @if ($dispatch)
                            @if (in_array($merchandiseRequest->status, [\App\Models\MerchandiseRequest::STATUS_SENT, \App\Models\MerchandiseRequest::STATUS_COMPLETED], true))
                                <a href="{{ route('dispatches.delivery-note', $dispatch) }}" class="button-primary compact-button btn-compact wms-button-with-icon" target="_blank" rel="noopener noreferrer">
                                    <span class="wms-button-icon" aria-hidden="true"><x-module-icon name="printer" /></span>
                                    Imprimir albarán
                                </a>
                            @endif
                        @endif

                        <a href="{{ route('merchandise-requests.index') }}" class="button-secondary compact-button btn-compact">Volver</a>
                    </div>
                </div>
            </details>
        @endunless

        @if ($dispatch && $dispatch->lines->contains(fn ($line) => $line->is_extra_line))
            <section class="surface-card compact-card order-lines-card">
                <div class="order-lines-head">
                    <strong>Carga real adicional</strong>
                    <span class="ops-page-meta">{{ $dispatch->lines->where('is_extra_line', true)->count() }} líneas</span>
                </div>

                <div class="order-table-wrap">
                    <table class="order-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Descripción</th>
                                <th>Lote</th>
                                <th>Cargado</th>
                                <th>Uds/pallet</th>
                                <th>Tipo</th>
                                <th>Observaciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dispatch->lines->where('is_extra_line', true) as $extraLine)
                                <tr>
                                    <td class="order-table-strong">{{ $extraLine->sku }}</td>
                                    <td>{{ $extraLine->description }}</td>
                                    <td>{{ $extraLine->lot ?: 'Sin lote' }}</td>
                                    <td>{{ $extraLine->loadedQuantityLabel() }}</td>
                                    <td>{{ $extraLine->unitsLabel() }}</td>
                                    <td><span class="wms-line-type-pill wms-line-type-pill--{{ $extraLine->lineType() }}">{{ $extraLine->lineTypeLabel() }}</span></td>
                                    <td>{{ $extraLine->loading_notes ?: 'Sin observaciones' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
