@extends('layouts.dashboard')

@section('title', 'Detalle de salida | MAXIMO WMS')
@section('topbar_title', 'Detalle de salida')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <a href="{{ route('dispatches.index') }}">Salidas</a>
        <span>/</span>
        <span>{{ $dispatch->dispatchNumber() }}</span>
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
                <dt>Total pallets</dt>
                <dd>{{ number_format($dispatch->palletsCount(), 0, ',', '.') }}</dd>
            </div>
            <div>
                <dt>Total cargado</dt>
                <dd>{{ number_format($dispatch->loadedPalletsCount(), 0, ',', '.') }}</dd>
            </div>
            <div>
                <dt>Fecha envio</dt>
                <dd>{{ $dispatch->sent_at?->format('d/m/Y H:i') ?: 'Pendiente' }}</dd>
            </div>
            <div>
                <dt>Direccion entrega</dt>
                <dd>{{ $dispatch->client?->formattedDeliveryAddress() ?: 'Pendiente en ficha de cliente' }}</dd>
            </div>
            <div>
                <dt>Carga confirmada</dt>
                <dd>{{ $dispatch->hasConfirmedLoading() ? optional($dispatch->latestLoadingConfirmationAt())->format('d/m/Y H:i') : 'Pendiente de confirmar' }}</dd>
            </div>
        </dl>
    </section>

    <section class="surface-card compact-card merchandise-request-detail-card">
        <div class="ops-section-heading">
            <div>
                <strong>Confirmacion de carga real</strong>
                <p class="merchandise-request-summary-copy">Antes de marcar la salida como enviada o completada, confirma linea a linea lo realmente cargado.</p>
            </div>
            <span class="ops-status badge-compact">{{ $dispatch->hasConfirmedLoading() ? 'Confirmada' : 'Pendiente' }}</span>
        </div>

        <form method="POST" action="{{ route('dispatches.confirm-loading', $dispatch) }}" class="dispatch-loading-form">
            @csrf
            @method('PATCH')

            <div class="data-table-wrap">
                <table class="data-table table-compact">
                    <thead>
                        <tr>
                            <th>Mercancia</th>
                            <th>Solicitados</th>
                            <th>Cargados</th>
                            <th>Observaciones de carga</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dispatch->lines as $line)
                            <tr>
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
                                        name="lines[{{ $line->id }}][loaded_pallets]"
                                        value="{{ old('lines.'.$line->id.'.loaded_pallets', $line->loadedPallets()) }}"
                                        class="auth-input merchandise-request-summary-input"
                                        required
                                    >
                                </td>
                                <td>
                                    <textarea
                                        name="lines[{{ $line->id }}][loading_notes]"
                                        class="auth-input"
                                        rows="2"
                                        placeholder="Opcional. Recomendado si la carga difiere."
                                    >{{ old('lines.'.$line->id.'.loading_notes', $line->loading_notes) }}</textarea>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="dispatch-inline-help">
                Puedes dejar una linea a cero si finalmente no se carga. Si la cantidad real difiere de la solicitada, deja observacion para trazabilidad.
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
            Pendiente integrar descuento real de stock cuando el modelo de stock definitivo quede cerrado y permita trazar con seguridad los palets exactos expedidos.
        </div>
    </section>

    <section class="surface-card stock-table-shell compact-card">
        <div class="ops-section-heading">
            <strong>Lineas de salida</strong>
            <span class="ops-page-meta">{{ $dispatch->lines->count() }} lineas</span>
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
                        <th>Observaciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dispatch->lines as $line)
                        <tr>
                            <td><strong>{{ $line->sku }}</strong></td>
                            <td>{{ $line->description }}</td>
                            <td>{{ $line->lot ?: 'Sin lote' }}</td>
                            <td>{{ number_format($line->units_per_pallet ?? 0, 0, ',', '.') }}</td>
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
