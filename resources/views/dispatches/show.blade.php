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

    <section class="surface-card compact-card merchandise-request-detail-card">
        <div class="ops-page-headline">
            <div>
                <h2 class="ops-page-title page-title-compact">{{ $dispatch->dispatchNumber() }}</h2>
                <p class="access-request-detail-meta">{{ $dispatch->client?->name ?? 'Sin cliente' }}</p>
            </div>
            <span class="status-badge dispatch-status dispatch-status--{{ $dispatch->status }}">{{ $dispatch->statusLabel() }}</span>
        </div>

        <dl class="access-request-detail-grid">
            <div>
                <dt>Tipo</dt>
                <dd>{{ $dispatch->type === \App\Models\GoodsDispatch::TYPE_REQUEST ? 'Desde solicitud' : 'Manual' }}</dd>
            </div>
            <div>
                <dt>Total solicitado</dt>
                <dd>{{ number_format($dispatch->palletsCount(), 0, ',', '.') }}</dd>
            </div>
            <div>
                <dt>Total cargado</dt>
                <dd>{{ number_format($dispatch->loadedPalletsCount(), 0, ',', '.') }}</dd>
            </div>
            <div>
                <dt>Fecha envío</dt>
                <dd>{{ $dispatch->sent_at?->format('d/m/Y H:i') ?: 'Pendiente' }}</dd>
            </div>
            <div>
                <dt>Albaran enviado</dt>
                <dd>{{ $dispatch->delivery_note_sent_at?->format('d/m/Y H:i') ?: 'Pendiente' }}</dd>
            </div>
            <div>
                <dt>Carga confirmada</dt>
                <dd>{{ $dispatch->hasConfirmedLoading() ? optional($dispatch->latestLoadingConfirmationAt())->format('d/m/Y H:i') : 'Pendiente de confirmar' }}</dd>
            </div>
            <div>
                <dt>Direccion entrega</dt>
                <dd>{{ $dispatch->client?->formattedDeliveryAddress() ?: 'Pendiente en ficha de cliente' }}</dd>
            </div>
            <div>
                <dt>Diferencias</dt>
                <dd>{{ $dispatch->hasLoadingDifferences() ? 'Si, revisar carga real' : 'No detectadas' }}</dd>
            </div>
        </dl>
    </section>

    <section class="surface-card stock-table-shell compact-card">
        <div class="ops-section-heading">
            <div>
                <strong>{{ $dispatch->merchandiseRequest ? 'Lineas solicitadas' : 'Lineas base de la salida' }}</strong>
                <p class="merchandise-request-summary-copy">
                    {{ $dispatch->merchandiseRequest ? 'Estas líneas vienen del pedido original del cliente.' : 'Estas líneas sirven como referencia base para la expedición manual.' }}
                </p>
            </div>
            <span class="ops-page-meta">{{ $requestedLines->count() }} líneas</span>
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
                        <th>Pallets cargados</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requestedLines as $requestedLine)
                        @php
                            $dispatchLine = $dispatch->lines->first(fn ($line) => (int) $line->source_request_line_id === (int) ($requestedLine->id ?? 0))
                                ?? $dispatch->lines->first(fn ($line) => ! $line->is_extra_line && (int) $line->item_id === (int) ($requestedLine->item_id ?? 0));
                        @endphp
                        <tr>
                            <td><strong>{{ $requestedLine->item?->sku ?? $requestedLine->sku ?? 'Articulo eliminado' }}</strong></td>
                            <td>{{ $requestedLine->item?->description ?? $requestedLine->description ?? 'Sin descripcion' }}</td>
                            <td>{{ $requestedLine->lot ?: 'Sin lote' }}</td>
                            <td>{{ number_format($requestedLine->units_per_pallet ?? 0, 0, ',', '.') }}</td>
                            <td>{{ number_format($requestedLine->requested_pallets ?? $requestedLine->requestedPallets(), 0, ',', '.') }}</td>
                            <td>{{ number_format($dispatchLine?->loadedPallets() ?? ($requestedLine->requested_pallets ?? 0), 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="surface-card compact-card merchandise-request-detail-card">
        <div class="ops-section-heading">
            <div>
                <strong>Carga real</strong>
                <p class="merchandise-request-summary-copy">Aqui puedes confirmar lo realmente cargado, anadir referencias extra, registrar picos o dejar una linea a cero si finalmente no sale.</p>
            </div>
            <span class="ops-status badge-compact">{{ $dispatch->hasConfirmedLoading() ? 'Confirmada' : 'Pendiente' }}</span>
        </div>

        <div class="dispatch-inline-help">
            Total solicitado: {{ number_format($dispatch->palletsCount(), 0, ',', '.') }} pallets.
            Total cargado actual: {{ number_format($dispatch->loadedPalletsCount(), 0, ',', '.') }} pallets.
            {{ $dispatch->hasLoadingDifferences() ? 'Hay diferencias entre pedido y carga real.' : 'No hay diferencias registradas por ahora.' }}
        </div>

        <form method="POST" action="{{ route('dispatches.confirm-loading', $dispatch) }}" class="dispatch-loading-form" data-dispatch-loading-editor data-client-id="{{ $dispatch->client_id }}" data-search-endpoint="{{ $searchEndpoint }}">
            @csrf
            @method('PATCH')

            <div class="data-table-wrap">
                <table class="data-table table-compact dispatch-loading-table">
                    <thead>
                        <tr>
                            <th>Origen</th>
                            <th>Mercancia</th>
                            <th>Solicitados</th>
                            <th>Pallets cargados</th>
                            <th>Observaciones de carga</th>
                            <th>Accion</th>
                        </tr>
                    </thead>
                    <tbody data-dispatch-loading-rows>
                        @foreach ($dispatch->lines as $line)
                            @php
                                $input = $existingLineInputs->get((string) $line->id, []);
                                $removeRequested = filter_var($input['remove'] ?? false, FILTER_VALIDATE_BOOL);
                            @endphp
                            @continue($line->is_extra_line && $removeRequested)
                            <tr data-dispatch-loading-row>
                                <td>
                                    <span class="ops-status badge-compact">{{ $line->lineOriginLabel() }}</span>
                                    <input type="hidden" name="lines[line_{{ $line->id }}][line_id]" value="{{ $line->id }}">
                                    @if ($line->item_id)
                                        <input type="hidden" name="lines[line_{{ $line->id }}][item_id]" value="{{ $line->item_id }}">
                                    @endif
                                    <input type="hidden" name="lines[line_{{ $line->id }}][remove]" value="0" data-dispatch-remove-flag>
                                </td>
                                <td>
                                    <div class="stock-cell-main">
                                        <strong>{{ $line->sku }}</strong>
                                        <span class="users-table-email">{{ $line->description }} · {{ $line->lot ?: 'Sin lote' }}</span>
                                    </div>
                                </td>
                                <td>{{ number_format($line->requestedPallets(), 0, ',', '.') }}</td>
                                <td>
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        name="lines[line_{{ $line->id }}][loaded_pallets]"
                                        value="{{ old('lines.line_'.$line->id.'.loaded_pallets', $input['loaded_pallets'] ?? $line->loadedPallets()) }}"
                                        class="auth-input merchandise-request-summary-input"
                                        required
                                    >
                                </td>
                                <td>
                                    <textarea
                                        name="lines[line_{{ $line->id }}][loading_notes]"
                                        class="auth-input"
                                        rows="2"
                                        placeholder="Opcional. Recomendado si la carga difiere."
                                    >{{ old('lines.line_'.$line->id.'.loading_notes', $input['loading_notes'] ?? $line->loading_notes) }}</textarea>
                                </td>
                                <td class="dispatch-loading-actions">
                                    @if ($line->is_extra_line)
                                        <button type="button" class="button-secondary compact-button btn-table" data-dispatch-loading-remove>
                                            Eliminar linea extra
                                        </button>
                                    @else
                                        <span class="users-table-email">Usa 0 si no se carga.</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                        @foreach ($extraLineInputs as $rowKey => $payload)
                            <tr data-dispatch-loading-row>
                                <td>
                                    <span class="ops-status badge-compact">Extra</span>
                                    <input type="hidden" name="lines[{{ $rowKey }}][remove]" value="0" data-dispatch-remove-flag>
                                </td>
                                <td>
                                    <label class="sr-only" for="dispatch-extra-item-{{ $rowKey }}">Mercancia extra</label>
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
                                                placeholder="Buscar por SKU o descripción"
                                                data-autocomplete-input
                                            >
                                            <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear {{ blank($payload['item_id'] ?? null) ? 'hidden' : '' }}>Limpiar</button>
                                        </div>
                                        <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                                            <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe al menos 2 caracteres...</div>
                                            <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>0</td>
                                <td>
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        name="lines[{{ $rowKey }}][loaded_pallets]"
                                        value="{{ $payload['loaded_pallets'] ?? 0 }}"
                                        class="auth-input merchandise-request-summary-input"
                                        required
                                    >
                                </td>
                                <td>
                                    <textarea
                                        name="lines[{{ $rowKey }}][loading_notes]"
                                        class="auth-input"
                                        rows="2"
                                        placeholder="Ejemplo: pico, sustitucion o referencia adicional."
                                    >{{ $payload['loading_notes'] ?? '' }}</textarea>
                                </td>
                                <td class="dispatch-loading-actions">
                                    <button type="button" class="button-secondary compact-button btn-table" data-dispatch-loading-remove>
                                        Eliminar linea extra
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="dispatch-loading-toolbar">
                <button type="button" class="button-secondary compact-button btn-compact" data-dispatch-loading-add>
                    Añadir referencia a la carga
                </button>
                <span class="users-table-email">Usa esta opción para picos, sustituciones, referencias adicionales o mercancía no prevista en el pedido original.</span>
            </div>

            <template data-dispatch-loading-row-template>
                <tr data-dispatch-loading-row>
                    <td>
                        <span class="ops-status badge-compact">Extra</span>
                        <input type="hidden" name="lines[__KEY__][remove]" value="0" data-dispatch-remove-flag>
                    </td>
                    <td>
                        <label class="sr-only" for="dispatch-extra-item-__KEY__">Mercancia extra</label>
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
                                    placeholder="Buscar por SKU o descripción"
                                    data-autocomplete-input
                                >
                                <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear hidden>Limpiar</button>
                            </div>
                            <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                                <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe al menos 2 caracteres...</div>
                                <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                            </div>
                        </div>
                    </td>
                    <td>0</td>
                    <td>
                        <input
                            type="number"
                            min="0"
                            step="1"
                            name="lines[__KEY__][loaded_pallets]"
                            value="0"
                            class="auth-input merchandise-request-summary-input"
                            required
                        >
                    </td>
                    <td>
                        <textarea
                            name="lines[__KEY__][loading_notes]"
                            class="auth-input"
                            rows="2"
                            placeholder="Ejemplo: pico, sustitucion o referencia adicional."
                        ></textarea>
                    </td>
                    <td class="dispatch-loading-actions">
                        <button type="button" class="button-secondary compact-button btn-table" data-dispatch-loading-remove>
                            Eliminar linea extra
                        </button>
                    </td>
                </tr>
            </template>

            <div class="dispatch-inline-help">
                Puedes dejar una línea solicitada a cero si finalmente no se carga. Para sustituciones o mercancía adicional, añade una línea extra y documenta la observación de carga.
            </div>

            <div class="item-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Confirmar carga real</button>
            </div>
        </form>
    </section>

    <section class="surface-card compact-card merchandise-request-detail-card">
        <div class="dispatch-actions-grid">
            <form method="POST" action="{{ route('dispatches.update-status', $dispatch) }}" class="dispatch-inline-form">
                @csrf
                @method('PATCH')
                <label class="auth-field">
                    <span>Cambiar estado de salida</span>
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

            <div class="dispatch-action-stack">
                @if ($dispatch->merchandiseRequest)
                    <a href="{{ route('dispatches.requests.show', $dispatch->merchandiseRequest) }}" class="button-secondary compact-button btn-compact">Ver pedido origen</a>
                @endif

                @if (in_array($dispatch->status, [\App\Models\GoodsDispatch::STATUS_SENT, \App\Models\GoodsDispatch::STATUS_COMPLETED], true))
                    <a href="{{ route('dispatches.delivery-note', $dispatch) }}" class="button-primary compact-button btn-compact" target="_blank" rel="noopener noreferrer">Imprimir albaran</a>
                @endif
            </div>
        </div>

        <div class="dispatch-inline-help dispatch-stock-warning">
            Pendiente seleccionar partida/lote concreto en preparacion de salida cuando se active descuento real de stock.
        </div>
    </section>

    <section class="surface-card stock-table-shell compact-card">
        <div class="ops-section-heading">
            <strong>Carga real registrada</strong>
            <span class="ops-page-meta">{{ $dispatch->lines->count() }} líneas</span>
        </div>

        <div class="data-table-wrap">
            <table class="data-table table-compact">
                <thead>
                    <tr>
                        <th>Origen</th>
                        <th>Mercancia</th>
                        <th>Descripcion</th>
                        <th>Lote</th>
                        <th>Pallets solicitados</th>
                        <th>Pallets cargados</th>
                        <th>Observaciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dispatch->lines as $line)
                        <tr>
                            <td>{{ $line->lineOriginLabel() }}</td>
                            <td><strong>{{ $line->sku }}</strong></td>
                            <td>{{ $line->description }}</td>
                            <td>{{ $line->lot ?: 'Sin lote' }}</td>
                            <td>{{ number_format($line->requestedPallets(), 0, ',', '.') }}</td>
                            <td>{{ number_format($line->loadedPallets(), 0, ',', '.') }}</td>
                            <td>{{ $line->loading_notes ?: '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection





