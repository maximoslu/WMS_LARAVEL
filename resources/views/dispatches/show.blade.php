@extends('layouts.dashboard')

@section('title', 'Detalle de salida | MAXIMO WMS')
@section('topbar_title', 'Detalle de salida')

@php
    $submittedLines = collect(old('lines', []));
    $existingLineInputs = $submittedLines
        ->filter(fn ($payload) => filled($payload['line_id'] ?? null))
        ->mapWithKeys(fn ($payload) => [(string) $payload['line_id'] => $payload]);
    $extraLineInputs = $submittedLines->filter(fn ($payload) => blank($payload['line_id'] ?? null));
    $requestedLines = $dispatch->merchandiseRequest?->lines ?? $dispatch->lines->reject(fn ($line) => $line->is_extra_line);
    $requestedPallets = $dispatch->palletsCount();
    $requestedPeaks = $dispatch->peaksCount();
    $loadedPallets = $dispatch->loadedPalletsCount();
    $loadedPeaks = $dispatch->loadedPeaksCount();
@endphp

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Salidas', 'href' => route('dispatches.index')],
            ['label' => $dispatch->dispatchNumber()],
        ];
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
            <span class="status-chip">{{ $dispatch->type === \App\Models\GoodsDispatch::TYPE_REQUEST ? 'Salida desde pedido' : 'Salida manual' }}</span>
            <h2 class="ops-page-title page-title-compact">{{ $dispatch->dispatchNumber() }}</h2>
            <p>{{ $dispatch->client?->name ?? 'Sin cliente' }}</p>
        </div>
        <div class="wms-flow-hero-side">
            <span class="status-badge dispatch-status dispatch-status--{{ $dispatch->status }}">{{ $dispatch->statusLabel() }}</span>
            @if ($dispatch->status === \App\Models\GoodsDispatch::STATUS_SENT)
                <form method="POST" action="{{ route('dispatches.update-status', $dispatch) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="{{ \App\Models\GoodsDispatch::STATUS_COMPLETED }}">
                    <button type="submit" class="button-primary compact-button btn-compact" onclick="return confirm('¿Marcar esta salida como completada?')">
                        Marcar como completado
                    </button>
                </form>
            @endif
        </div>
    </section>

    <section class="wms-detail-grid">
        <article class="surface-card compact-card wms-flow-card">
            <div class="wms-section-head">
                <div>
                    <strong>Resumen de expedición</strong>
                    <p class="merchandise-request-summary-copy">Lo importante está visible arriba para preparar, revisar y enviar sin perderte en tablas crudas.</p>
                </div>
            </div>

            <div class="wms-summary-kpis">
                <div class="wms-kpi-tile">
                    <span>Pallets solicitados</span>
                    <strong>{{ number_format($requestedPallets, 0, ',', '.') }}</strong>
                </div>
                <div class="wms-kpi-tile">
                    <span>Picos solicitados</span>
                    <strong>{{ number_format($requestedPeaks, 0, ',', '.') }}</strong>
                </div>
                <div class="wms-kpi-tile">
                    <span>Pallets cargados</span>
                    <strong>{{ number_format($loadedPallets, 0, ',', '.') }}</strong>
                </div>
                <div class="wms-kpi-tile">
                    <span>Picos cargados</span>
                    <strong>{{ number_format($loadedPeaks, 0, ',', '.') }}</strong>
                </div>
                <div class="wms-kpi-tile">
                    <span>Carga confirmada</span>
                    <strong>{{ $dispatch->hasConfirmedLoading() ? optional($dispatch->latestLoadingConfirmationAt())->format('d/m/Y H:i') : 'Pendiente' }}</strong>
                </div>
                <div class="wms-kpi-tile">
                    <span>Dirección</span>
                    <strong>{{ $dispatch->client?->formattedDeliveryAddress() ?: 'Pendiente en ficha de cliente' }}</strong>
                </div>
            </div>
        </article>

        <article class="surface-card compact-card wms-flow-card">
            <div class="wms-section-head">
                <div>
                    <strong>Estado y documentos</strong>
                    <p class="merchandise-request-summary-copy">Acciones principales bien agrupadas para el cierre operativo.</p>
                </div>
            </div>

            <div class="wms-action-grid">
                <form method="POST" action="{{ route('dispatches.update-status', $dispatch) }}" class="wms-action-card">
                    @csrf
                    @method('PATCH')
                    <strong>Cambiar estado</strong>
                    <p>Solo podrás enviar o completar cuando la carga real quede confirmada.</p>

                    <label class="auth-field">
                        <span>Nuevo estado</span>
                        <select name="status" class="auth-input">
                            @foreach (\App\Models\GoodsDispatch::statusOptions() as $status => $label)
                                @if (! ($dispatch->merchandise_request_id && $status === \App\Models\GoodsDispatch::STATUS_DRAFT))
                                    <option value="{{ $status }}" @selected($dispatch->status === $status)>{{ $label }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>

                    <button type="submit" class="button-primary compact-button btn-compact">Guardar estado</button>
                </form>

                <div class="wms-action-card">
                    <strong>Accesos rápidos</strong>
                    <p>Todo lo necesario para revisar el origen y emitir documentación.</p>

                    @if ($dispatch->merchandiseRequest)
                        <a href="{{ route('dispatches.requests.show', $dispatch->merchandiseRequest) }}" class="button-secondary compact-button btn-compact">Ver pedido origen</a>
                    @endif

                    @if (in_array($dispatch->status, [\App\Models\GoodsDispatch::STATUS_SENT, \App\Models\GoodsDispatch::STATUS_COMPLETED], true))
                        <a href="{{ route('dispatches.delivery-note', $dispatch) }}" class="button-primary compact-button btn-compact wms-button-with-icon" target="_blank" rel="noopener noreferrer">
                            <span class="wms-button-icon" aria-hidden="true"><x-module-icon name="printer" /></span>
                            Imprimir albarán
                        </a>
                    @endif

                    @if ($dispatch->hasStockApplied())
                        <span class="status-chip status-chip--success">
                            Stock descontado el {{ $dispatch->stock_applied_at->format('d/m/Y H:i') }}
                        </span>
                    @else
                        <div class="dispatch-inline-help dispatch-stock-warning">
                            Stock pendiente de descontar al enviar.
                        </div>
                    @endif
                </div>

                <form method="POST" action="{{ route('dispatches.own-truck.update', $dispatch) }}" class="wms-action-card">
                    @csrf
                    @method('PUT')
                    <strong>Cami&oacute;n propio</strong>
                    <p>Marca esta salida solo si la entrega se realiza con cami&oacute;n propio de MAXIMO.</p>

                    <label class="auth-field">
                        <span>
                            <input type="hidden" name="camion_propio" value="0">
                            <input type="checkbox" name="camion_propio" value="1" @checked(old('camion_propio', $dispatch->camion_propio))>
                            Cami&oacute;n propio
                        </span>
                    </label>

                    <button type="submit" class="button-secondary compact-button btn-compact">Guardar cami&oacute;n</button>
                </form>
            </div>
        </article>
    </section>

    <section class="surface-card compact-card wms-flow-card">
        <div class="wms-section-head">
            <div>
                <strong>{{ $dispatch->merchandiseRequest ? 'Líneas solicitadas' : 'Líneas base de la salida' }}</strong>
                <p class="merchandise-request-summary-copy">
                    {{ $dispatch->merchandiseRequest ? 'Estas líneas vienen del pedido original del cliente.' : 'Estas líneas sirven como referencia base para la expedición manual.' }}
                </p>
            </div>
            <span class="ops-page-meta">{{ $requestedLines->count() }} líneas</span>
        </div>

        <div class="wms-line-card-list">
            @foreach ($requestedLines as $requestedLine)
                @php
                    $dispatchLine = $dispatch->lines->first(fn ($line) => (int) $line->source_request_line_id === (int) ($requestedLine->id ?? 0))
                        ?? $dispatch->lines->first(fn ($line) => ! $line->is_extra_line && (int) $line->item_id === (int) ($requestedLine->item_id ?? 0)
                            && (string) $line->line_type === (string) ($requestedLine->line_type ?? 'pallet')
                            && (int) ($line->stock_peak_index ?? 0) === (int) ($requestedLine->stock_peak_index ?? 0));
                @endphp
                <article class="wms-line-card">
                    <div class="wms-line-card-head">
                        <div>
                            <strong>{{ $requestedLine->item?->sku ?? $requestedLine->sku ?? 'Articulo eliminado' }}</strong>
                            <p>{{ $requestedLine->item?->description ?? $requestedLine->description ?? 'Sin descripción' }}</p>
                        </div>
                        <span class="wms-line-type-pill wms-line-type-pill--{{ method_exists($requestedLine, 'lineType') ? $requestedLine->lineType() : 'pallet' }}">
                            {{ method_exists($requestedLine, 'lineTypeLabel') ? $requestedLine->lineTypeLabel() : 'Pallet completo' }}
                        </span>
                    </div>

                    <div class="wms-line-card-meta">
                        <span>Solicitado {{ method_exists($requestedLine, 'requestedQuantityLabel') ? $requestedLine->requestedQuantityLabel() : number_format($requestedLine->requested_pallets ?? 0, 0, ',', '.').' pallets' }}</span>
                        <span>{{ method_exists($requestedLine, 'unitsLabel') ? $requestedLine->unitsLabel() : number_format($requestedLine->units_per_pallet ?? 0, 0, ',', '.').' uds/pallet' }}</span>
                        <span>{{ $requestedLine->lot ? 'Lote '.$requestedLine->lot : 'Sin lote' }}</span>
                        @if ($requestedLine->stockPallet?->location_text ?? false)
                            <span>Ubicación {{ $requestedLine->stockPallet->location_text }}</span>
                        @endif
                        @if ($dispatchLine)
                            <span>Cargado {{ $dispatchLine->loadedQuantityLabel() }}</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="surface-card compact-card wms-flow-card">
        <div class="wms-section-head">
            <div>
                <strong>Carga real</strong>
                <p class="merchandise-request-summary-copy">Aquí puedes ajustar cantidades, eliminar líneas extra, añadir sustituciones y registrar si algo sale como pallet o como pico.</p>
            </div>
            <span class="ops-status">{{ $dispatch->hasConfirmedLoading() ? 'Confirmada' : 'Pendiente' }}</span>
        </div>

        <div class="dispatch-inline-help">
            Solicitado: {{ number_format($requestedPallets, 0, ',', '.') }} pallets y {{ number_format($requestedPeaks, 0, ',', '.') }} picos.
            Cargado: {{ number_format($loadedPallets, 0, ',', '.') }} pallets y {{ number_format($loadedPeaks, 0, ',', '.') }} picos.
            {{ $dispatch->hasLoadingDifferences() ? 'Hay diferencias entre lo pedido y la carga real.' : 'No hay diferencias registradas por ahora.' }}
        </div>

        <form method="POST" action="{{ route('dispatches.confirm-loading', $dispatch) }}" class="dispatch-loading-form" data-dispatch-loading-editor data-client-id="{{ $dispatch->client_id }}" data-search-endpoint="{{ $searchEndpoint }}">
            @csrf
            @method('PATCH')

            <div class="wms-loading-editor-list" data-dispatch-loading-rows>
                @foreach ($dispatch->lines as $line)
                    @php
                        $input = $existingLineInputs->get((string) $line->id, []);
                        $removeRequested = filter_var($input['remove'] ?? false, FILTER_VALIDATE_BOOL);
                    @endphp
                    @continue($line->is_extra_line && $removeRequested)

                    <article class="wms-loading-row" data-dispatch-loading-row>
                        <input type="hidden" name="lines[line_{{ $line->id }}][line_id]" value="{{ $line->id }}">
                        <input type="hidden" name="lines[line_{{ $line->id }}][item_id]" value="{{ $line->item_id }}">
                        <input type="hidden" name="lines[line_{{ $line->id }}][line_type]" value="{{ $line->lineType() }}">
                        <input type="hidden" name="lines[line_{{ $line->id }}][stock_pallet_id]" value="{{ $line->stock_pallet_id }}">
                        <input type="hidden" name="lines[line_{{ $line->id }}][stock_peak_index]" value="{{ $line->stock_peak_index }}">
                        <input type="hidden" name="lines[line_{{ $line->id }}][remove]" value="0" data-dispatch-remove-flag>

                        <div class="wms-loading-row-head">
                            <div>
                                <strong>{{ $line->sku }}</strong>
                                <p>{{ $line->description }}</p>
                            </div>
                            <div class="wms-line-card-badges">
                                <span class="ops-status">{{ $line->lineOriginLabel() }}</span>
                                <span class="wms-line-type-pill wms-line-type-pill--{{ $line->lineType() }}">{{ $line->lineTypeLabel() }}</span>
                            </div>
                        </div>

                        <div class="wms-line-card-meta">
                            <span>Solicitado {{ $line->requestedQuantityLabel() }}</span>
                            <span>{{ $line->unitsLabel() }}</span>
                            <span>{{ $line->lot ? 'Lote '.$line->lot : 'Sin lote' }}</span>
                            @if ($line->stockPallet?->location_text)
                                <span>Ubicación {{ $line->stockPallet->location_text }}</span>
                            @endif
                        </div>

                        <div class="wms-loading-row-fields">
                            <label class="auth-field">
                                <span>{{ $line->isPeakLine() ? 'Picos cargados' : 'Pallets cargados' }}</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    max="{{ $line->isPeakLine() ? 1 : '' }}"
                                    name="lines[line_{{ $line->id }}][loaded_quantity]"
                                    value="{{ old('lines.line_'.$line->id.'.loaded_quantity', $input['loaded_quantity'] ?? $line->loadedQuantity()) }}"
                                    class="auth-input merchandise-request-summary-input"
                                    required
                                >
                            </label>

                            <label class="auth-field">
                                <span>Observaciones de carga</span>
                                <textarea
                                    name="lines[line_{{ $line->id }}][loading_notes]"
                                    class="auth-input"
                                    rows="2"
                                    placeholder="Opcional. Recomendado si la carga difiere."
                                >{{ old('lines.line_'.$line->id.'.loading_notes', $input['loading_notes'] ?? $line->loading_notes) }}</textarea>
                            </label>

                            <div class="wms-loading-row-action">
                                @if ($line->is_extra_line)
                                    <button type="button" class="button-secondary compact-button btn-compact" data-dispatch-loading-remove>
                                        Eliminar línea extra
                                    </button>
                                @else
                                    <p>Usa <strong>0</strong> si finalmente no se carga esta línea.</p>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach

                @foreach ($extraLineInputs as $rowKey => $payload)
                    <article class="wms-loading-row wms-loading-row--extra" data-dispatch-loading-row>
                        <input type="hidden" name="lines[{{ $rowKey }}][remove]" value="0" data-dispatch-remove-flag>
                        <input type="hidden" name="lines[{{ $rowKey }}][line_type]" value="{{ $payload['line_type'] ?? 'pallet' }}" data-dispatch-extra-line-type>
                        <input type="hidden" name="lines[{{ $rowKey }}][stock_pallet_id]" value="{{ $payload['stock_pallet_id'] ?? '' }}" data-dispatch-extra-stock-pallet-id>
                        <input type="hidden" name="lines[{{ $rowKey }}][stock_peak_index]" value="{{ $payload['stock_peak_index'] ?? '' }}" data-dispatch-extra-peak-index>

                        <div class="wms-loading-row-head">
                            <div>
                                <strong>Línea extra</strong>
                                <p>Añade sustituciones, mercancía adicional o un pico no previsto inicialmente.</p>
                            </div>
                            <div class="wms-line-card-badges">
                                <span class="ops-status">Extra</span>
                                <span class="wms-line-type-pill" data-dispatch-extra-type-label>{{ ($payload['line_type'] ?? 'pallet') === 'peak' ? 'Pico' : 'Pallet completo' }}</span>
                            </div>
                        </div>

                        <div
                            class="ajax-autocomplete"
                            data-ajax-autocomplete
                            data-endpoint="{{ $searchEndpoint }}"
                            data-min-chars="2"
                            data-empty-message="Escribe al menos 2 caracteres para buscar referencias."
                            data-no-results-message="Sin resultados"
                            data-searching-message="Buscando..."
                            data-error-message="Error al buscar"
                            data-dispatch-extra-picker
                        >
                            <div class="ajax-autocomplete-control">
                                <input type="hidden" name="lines[{{ $rowKey }}][item_id]" value="{{ $payload['item_id'] ?? '' }}" data-dispatch-extra-item-id>
                                <input
                                    id="dispatch-extra-item-{{ $rowKey }}"
                                    type="text"
                                    name="lines[{{ $rowKey }}][item_search]"
                                    value="{{ $payload['item_search'] ?? '' }}"
                                    class="auth-input"
                                    autocomplete="off"
                                    placeholder="Buscar por SKU, descripción o lote"
                                    data-autocomplete-input
                                >
                                <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear {{ blank($payload['item_id'] ?? null) ? 'hidden' : '' }}>Limpiar</button>
                            </div>
                            <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                                <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe al menos 2 caracteres...</div>
                                <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                            </div>
                        </div>

                        <article class="wms-variant-preview" data-dispatch-extra-preview>
                            <strong>Selecciona una referencia</strong>
                            <p>Verás si estás añadiendo un pallet completo o un pico concreto.</p>
                        </article>

                        <div class="wms-loading-row-fields">
                            <label class="auth-field">
                                <span data-dispatch-extra-quantity-label>{{ ($payload['line_type'] ?? 'pallet') === 'peak' ? 'Picos cargados' : 'Pallets cargados' }}</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    name="lines[{{ $rowKey }}][loaded_quantity]"
                                    value="{{ $payload['loaded_quantity'] ?? 0 }}"
                                    class="auth-input merchandise-request-summary-input"
                                    required
                                >
                            </label>

                            <label class="auth-field">
                                <span>Observaciones de carga</span>
                                <textarea
                                    name="lines[{{ $rowKey }}][loading_notes]"
                                    class="auth-input"
                                    rows="2"
                                    placeholder="Ejemplo: pico, sustitución o referencia adicional."
                                >{{ $payload['loading_notes'] ?? '' }}</textarea>
                            </label>

                            <div class="wms-loading-row-action">
                                <button type="button" class="button-secondary compact-button btn-compact" data-dispatch-loading-remove>
                                    Eliminar línea extra
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="dispatch-loading-toolbar">
                <button type="button" class="button-secondary compact-button btn-compact" data-dispatch-loading-add>
                    Añadir referencia a la carga
                </button>
                <span class="users-table-email">Úsalo para sustituciones, picos añadidos o referencias extra realmente cargadas.</span>
            </div>

            <template data-dispatch-loading-row-template>
                <article class="wms-loading-row wms-loading-row--extra" data-dispatch-loading-row>
                    <input type="hidden" name="lines[__KEY__][remove]" value="0" data-dispatch-remove-flag>
                    <input type="hidden" name="lines[__KEY__][line_type]" value="pallet" data-dispatch-extra-line-type>
                    <input type="hidden" name="lines[__KEY__][stock_pallet_id]" value="" data-dispatch-extra-stock-pallet-id>
                    <input type="hidden" name="lines[__KEY__][stock_peak_index]" value="" data-dispatch-extra-peak-index>

                    <div class="wms-loading-row-head">
                        <div>
                            <strong>Línea extra</strong>
                            <p>Añade sustituciones, mercancía adicional o un pico no previsto inicialmente.</p>
                        </div>
                        <div class="wms-line-card-badges">
                            <span class="ops-status">Extra</span>
                            <span class="wms-line-type-pill" data-dispatch-extra-type-label>Pallet completo</span>
                        </div>
                    </div>

                    <div
                        class="ajax-autocomplete"
                        data-ajax-autocomplete
                        data-endpoint="{{ $searchEndpoint }}"
                        data-min-chars="2"
                        data-empty-message="Escribe al menos 2 caracteres para buscar referencias."
                        data-no-results-message="Sin resultados"
                        data-searching-message="Buscando..."
                        data-error-message="Error al buscar"
                        data-dispatch-extra-picker
                    >
                        <div class="ajax-autocomplete-control">
                            <input type="hidden" name="lines[__KEY__][item_id]" value="" data-dispatch-extra-item-id>
                            <input
                                id="dispatch-extra-item-__KEY__"
                                type="text"
                                name="lines[__KEY__][item_search]"
                                class="auth-input"
                                autocomplete="off"
                                placeholder="Buscar por SKU, descripción o lote"
                                data-autocomplete-input
                            >
                            <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear hidden>Limpiar</button>
                        </div>
                        <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                            <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe al menos 2 caracteres...</div>
                            <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                        </div>
                    </div>

                    <article class="wms-variant-preview" data-dispatch-extra-preview>
                        <strong>Selecciona una referencia</strong>
                        <p>Verás si estás añadiendo un pallet completo o un pico concreto.</p>
                    </article>

                    <div class="wms-loading-row-fields">
                        <label class="auth-field">
                            <span data-dispatch-extra-quantity-label>Pallets cargados</span>
                            <input
                                type="number"
                                min="0"
                                step="1"
                                name="lines[__KEY__][loaded_quantity]"
                                value="0"
                                class="auth-input merchandise-request-summary-input"
                                required
                            >
                        </label>

                        <label class="auth-field">
                            <span>Observaciones de carga</span>
                            <textarea
                                name="lines[__KEY__][loading_notes]"
                                class="auth-input"
                                rows="2"
                                placeholder="Ejemplo: pico, sustitución o referencia adicional."
                            ></textarea>
                        </label>

                        <div class="wms-loading-row-action">
                            <button type="button" class="button-secondary compact-button btn-compact" data-dispatch-loading-remove>
                                Eliminar línea extra
                            </button>
                        </div>
                    </div>
                </article>
            </template>

            <div class="item-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Confirmar carga real</button>
            </div>
        </form>
    </section>

    <section class="surface-card compact-card wms-flow-card">
        <div class="wms-section-head">
            <div>
                <strong>Carga real registrada</strong>
                <p class="merchandise-request-summary-copy">Vista limpia de lo que realmente salió, incluyendo extras y observaciones.</p>
            </div>
            <span class="ops-page-meta">{{ $dispatch->lines->count() }} líneas</span>
        </div>

        <div class="wms-line-card-list">
            @foreach ($dispatch->lines as $line)
                <article class="wms-line-card">
                    <div class="wms-line-card-head">
                        <div>
                            <strong>{{ $line->sku }}</strong>
                            <p>{{ $line->description }}</p>
                        </div>
                        <div class="wms-line-card-badges">
                            <span class="ops-status">{{ $line->lineOriginLabel() }}</span>
                            <span class="wms-line-type-pill wms-line-type-pill--{{ $line->lineType() }}">{{ $line->lineTypeLabel() }}</span>
                        </div>
                    </div>

                    <div class="wms-line-card-meta">
                        <span>Solicitado {{ $line->requestedQuantityLabel() }}</span>
                        <span>Cargado {{ $line->loadedQuantityLabel() }}</span>
                        <span>{{ $line->unitsLabel() }}</span>
                        <span>{{ $line->lot ? 'Lote '.$line->lot : 'Sin lote' }}</span>
                        <span>{{ $line->loading_notes ?: 'Sin observaciones' }}</span>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endsection
