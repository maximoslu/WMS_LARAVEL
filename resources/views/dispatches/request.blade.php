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
        $dispatch = $merchandiseRequest->dispatch;
        $requestedPallets = $merchandiseRequest->requestedPalletsCount();
        $requestedPeaks = $merchandiseRequest->requestedPeaksCount();
        $canGenerateDispatch = $dispatch === null && in_array($merchandiseRequest->status, [
            \App\Models\MerchandiseRequest::STATUS_PENDING,
            \App\Models\MerchandiseRequest::STATUS_PREPARING,
        ], true);
        $canEditLoading = $dispatch !== null && in_array($dispatch->status, [
            \App\Models\GoodsDispatch::STATUS_DRAFT,
            \App\Models\GoodsDispatch::STATUS_PREPARING,
        ], true);
        $statusSteps = [
            \App\Models\MerchandiseRequest::STATUS_PENDING => 'Registrado',
            \App\Models\MerchandiseRequest::STATUS_PREPARING => 'Preparación',
            \App\Models\MerchandiseRequest::STATUS_SENT => 'Enviado',
            \App\Models\MerchandiseRequest::STATUS_COMPLETED => 'Completado',
        ];
        $statusOrder = array_keys($statusSteps);
        $currentStatusIndex = array_search($merchandiseRequest->status, $statusOrder, true);
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

    <section class="surface-card compact-card warehouse-request-toolbar">
        <div class="warehouse-request-identity">
            <small>Pedido {{ $merchandiseRequest->referenceCode() }}</small>
            <strong>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</strong>
        </div>

        <dl class="warehouse-request-facts">
            <div>
                <dt>Estado</dt>
                <dd>
                    <span class="status-badge merchandise-request-status merchandise-request-status--{{ $merchandiseRequest->status }}">
                        {{ $merchandiseRequest->statusLabel() }}
                    </span>
                </dd>
            </div>
            <div>
                <dt>Fecha</dt>
                <dd>{{ $merchandiseRequest->submittedAt()?->format('d/m/Y H:i') }}</dd>
            </div>
            <div>
                <dt>Pallets</dt>
                <dd>{{ number_format($requestedPallets, 0, ',', '.') }}</dd>
            </div>
            <div>
                <dt>Picos</dt>
                <dd>{{ number_format($requestedPeaks, 0, ',', '.') }}</dd>
            </div>
        </dl>

        <div class="warehouse-request-actions">
            @if ($canGenerateDispatch)
                <form method="POST" action="{{ route('dispatches.requests.generate', $merchandiseRequest) }}">
                    @csrf
                    <input type="hidden" name="return_to_request" value="1">
                    <button type="submit" class="button-primary compact-button btn-compact">GENERAR SALIDA</button>
                </form>
            @elseif ($dispatch)
                <a href="{{ route('dispatches.show', $dispatch) }}" class="button-primary compact-button btn-compact">Ver salida</a>
            @endif

            <a href="{{ route('merchandise-requests.preparation-pdf', $merchandiseRequest) }}" class="button-secondary compact-button btn-compact wms-button-with-icon" target="_blank" rel="noopener noreferrer">
                <span class="wms-button-icon" aria-hidden="true"><x-module-icon name="printer" /></span>
                Imprimir preparación
            </a>
            <a href="{{ route('dispatches.requests.index') }}" class="button-secondary compact-button btn-compact">Volver</a>
        </div>
    </section>

    <section class="surface-card compact-card warehouse-request-lines" data-request-lines-section>
        <div class="warehouse-request-lines-head">
            <strong>LÍNEAS DEL PEDIDO Y CARGA REAL</strong>
            <span>{{ $merchandiseRequest->lines->count() }} {{ $merchandiseRequest->lines->count() === 1 ? 'línea' : 'líneas' }}</span>
        </div>

        @if ($dispatch)
            <form method="POST" action="{{ route('dispatches.confirm-loading', $dispatch) }}" class="warehouse-request-preparation-form">
                @csrf
                @method('PATCH')
                <input type="hidden" name="return_to_request" value="1">
        @endif

        <div class="warehouse-request-table-wrap">
            <table class="data-table table-compact warehouse-request-table" aria-label="Líneas del pedido y carga real">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Descripción</th>
                        <th>Lote</th>
                        <th>Ubicación</th>
                        <th>Sol. pallets</th>
                        <th>Sol. picos</th>
                        <th>Carg. pallets</th>
                        <th>Carg. picos</th>
                        <th>Estado</th>
                        <th>Observación</th>
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
                            $isConfirmed = $dispatchLine?->confirmed_at !== null;
                            $hasDifference = $isConfirmed && $dispatchLine->hasLoadingDifference();
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $line->item?->sku ?? 'Artículo eliminado' }}</strong>
                                <span class="wms-line-type-pill wms-line-type-pill--{{ $line->lineType() }}">{{ $line->lineTypeLabel() }}</span>
                                @if ($dispatchLine)
                                    <input type="hidden" name="lines[line_{{ $dispatchLine->id }}][line_id]" value="{{ $dispatchLine->id }}">
                                    <input type="hidden" name="lines[line_{{ $dispatchLine->id }}][item_id]" value="{{ $dispatchLine->item_id }}">
                                    <input type="hidden" name="lines[line_{{ $dispatchLine->id }}][line_type]" value="{{ $dispatchLine->lineType() }}">
                                    <input type="hidden" name="lines[line_{{ $dispatchLine->id }}][stock_pallet_id]" value="{{ $dispatchLine->stock_pallet_id }}">
                                    <input type="hidden" name="lines[line_{{ $dispatchLine->id }}][stock_peak_index]" value="{{ $dispatchLine->stock_peak_index }}">
                                    <input type="hidden" name="lines[line_{{ $dispatchLine->id }}][remove]" value="0">
                                @endif
                            </td>
                            <td>{{ $line->item?->description ?? 'Sin descripción' }}</td>
                            <td>{{ $line->lot ?: '—' }}</td>
                            <td>{{ $line->stockPallet?->location_text ?: '—' }}</td>
                            <td>{{ number_format($line->requestedPalletsCount(), 0, ',', '.') }}</td>
                            <td>{{ number_format($line->requestedPeaksCount(), 0, ',', '.') }}</td>
                            <td class="warehouse-request-quantity-cell">
                                @if ($dispatchLine?->isPalletLine())
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        name="lines[line_{{ $dispatchLine->id }}][loaded_quantity]"
                                        value="{{ old('lines.line_'.$dispatchLine->id.'.loaded_quantity', $dispatchLine->loadedQuantity()) }}"
                                        class="auth-input warehouse-request-quantity-input"
                                        aria-label="Pallets cargados para {{ $dispatchLine->sku }}"
                                        @disabled(! $canEditLoading)
                                        required
                                    >
                                @else
                                    —
                                @endif
                            </td>
                            <td class="warehouse-request-quantity-cell">
                                @if ($dispatchLine?->isPeakLine())
                                    <input
                                        type="number"
                                        min="0"
                                        max="1"
                                        step="1"
                                        name="lines[line_{{ $dispatchLine->id }}][loaded_quantity]"
                                        value="{{ old('lines.line_'.$dispatchLine->id.'.loaded_quantity', $dispatchLine->loadedQuantity()) }}"
                                        class="auth-input warehouse-request-quantity-input"
                                        aria-label="Picos cargados para {{ $dispatchLine->sku }}"
                                        @disabled(! $canEditLoading)
                                        required
                                    >
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if (! $dispatchLine)
                                    <span class="warehouse-load-state warehouse-load-state--pending">Sin salida</span>
                                @elseif (! $isConfirmed)
                                    <span class="warehouse-load-state warehouse-load-state--pending">Sin confirmar</span>
                                @elseif ($hasDifference)
                                    <span class="warehouse-load-state warehouse-load-state--difference">Diferencia</span>
                                @else
                                    <span class="warehouse-load-state warehouse-load-state--ok">OK</span>
                                @endif
                            </td>
                            <td>
                                @if ($dispatchLine)
                                    <input
                                        type="text"
                                        maxlength="250"
                                        name="lines[line_{{ $dispatchLine->id }}][loading_notes]"
                                        value="{{ old('lines.line_'.$dispatchLine->id.'.loading_notes', $dispatchLine->loading_notes) }}"
                                        class="auth-input warehouse-request-notes-input"
                                        placeholder="Opcional"
                                        aria-label="Observación de carga para {{ $dispatchLine->sku }}"
                                        @disabled(! $canEditLoading)
                                    >
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    @foreach ($dispatch?->lines?->where('is_extra_line', true) ?? collect() as $extraLine)
                        @php
                            $isConfirmed = $extraLine->confirmed_at !== null;
                        @endphp
                        <tr class="warehouse-request-extra-line">
                            <td>
                                <strong>{{ $extraLine->sku }}</strong>
                                <span class="wms-line-type-pill wms-line-type-pill--{{ $extraLine->lineType() }}">Extra</span>
                                <input type="hidden" name="lines[line_{{ $extraLine->id }}][line_id]" value="{{ $extraLine->id }}">
                                <input type="hidden" name="lines[line_{{ $extraLine->id }}][item_id]" value="{{ $extraLine->item_id }}">
                                <input type="hidden" name="lines[line_{{ $extraLine->id }}][line_type]" value="{{ $extraLine->lineType() }}">
                                <input type="hidden" name="lines[line_{{ $extraLine->id }}][stock_pallet_id]" value="{{ $extraLine->stock_pallet_id }}">
                                <input type="hidden" name="lines[line_{{ $extraLine->id }}][stock_peak_index]" value="{{ $extraLine->stock_peak_index }}">
                                <input type="hidden" name="lines[line_{{ $extraLine->id }}][remove]" value="0">
                            </td>
                            <td>{{ $extraLine->description }}</td>
                            <td>{{ $extraLine->lot ?: '—' }}</td>
                            <td>{{ $extraLine->stockPallet?->location_text ?: '—' }}</td>
                            <td>0</td>
                            <td>0</td>
                            <td class="warehouse-request-quantity-cell">
                                @if ($extraLine->isPalletLine())
                                    <input type="number" min="0" step="1" name="lines[line_{{ $extraLine->id }}][loaded_quantity]" value="{{ old('lines.line_'.$extraLine->id.'.loaded_quantity', $extraLine->loadedQuantity()) }}" class="auth-input warehouse-request-quantity-input" @disabled(! $canEditLoading) required>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="warehouse-request-quantity-cell">
                                @if ($extraLine->isPeakLine())
                                    <input type="number" min="0" max="1" step="1" name="lines[line_{{ $extraLine->id }}][loaded_quantity]" value="{{ old('lines.line_'.$extraLine->id.'.loaded_quantity', $extraLine->loadedQuantity()) }}" class="auth-input warehouse-request-quantity-input" @disabled(! $canEditLoading) required>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                <span class="warehouse-load-state warehouse-load-state--{{ $isConfirmed ? 'ok' : 'pending' }}">
                                    {{ $isConfirmed ? 'Extra' : 'Sin confirmar' }}
                                </span>
                            </td>
                            <td>
                                <input type="text" maxlength="250" name="lines[line_{{ $extraLine->id }}][loading_notes]" value="{{ old('lines.line_'.$extraLine->id.'.loading_notes', $extraLine->loading_notes) }}" class="auth-input warehouse-request-notes-input" placeholder="Opcional" @disabled(! $canEditLoading)>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($dispatch)
                @if ($canEditLoading)
                    <div class="warehouse-request-save-row">
                        <span>Carga: {{ $dispatch->hasConfirmedLoading() ? 'confirmada' : 'pendiente de confirmar' }}</span>
                        <button type="submit" class="button-primary compact-button btn-compact">GUARDAR PREPARACIÓN</button>
                    </div>
                @endif
            </form>
        @else
            <div class="warehouse-request-inline-state">Genera la salida para registrar la carga real.</div>
        @endif
    </section>

    <section class="surface-card compact-card warehouse-request-progress" aria-label="Seguimiento del pedido">
        @foreach ($statusSteps as $status => $label)
            @php
                $stepIndex = array_search($status, $statusOrder, true);
                $isComplete = $currentStatusIndex !== false && $stepIndex <= $currentStatusIndex;
            @endphp
            <span @class(['is-complete' => $isComplete])>{{ $label }}</span>
        @endforeach
    </section>
@endsection
