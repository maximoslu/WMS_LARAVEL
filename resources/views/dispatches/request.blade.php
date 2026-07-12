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
        $stockOptionsByItem = $stockOptionsByItem ?? [];
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
            <table class="data-table table-compact warehouse-request-table" aria-label="Lineas del pedido y carga real">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Descripción</th>
                        <th>Lote / ubicación</th>
                        <th>Solicitado</th>
                        <th>Stock disponible</th>
                        <th>Cargar desde</th>
                        <th>Pallets</th>
                        <th>Pico uds</th>
                        <th>Total cargado</th>
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
                            $lineKey = $dispatchLine ? 'line_'.$dispatchLine->id : null;
                            $requestedUnits = $dispatchLine?->requestedUnitsTotal() ?? (int) ($line->requested_units ?? 0);
                            $loadedPallets = $dispatchLine?->loadedPallets() ?? 0;
                            $loadedPartialUnits = $dispatchLine?->loadedPartialUnits() ?? 0;
                            $loadedUnits = $dispatchLine?->loadedUnitsTotal() ?? 0;
                            $selectedStockPalletId = $dispatchLine
                                ? old('lines.'.$lineKey.'.stock_pallet_id', $dispatchLine->stock_pallet_id ?? $line->stock_pallet_id)
                                : null;
                            $lineStockOptions = collect($stockOptionsByItem[$line->item_id] ?? []);
                            $selectedStockPallet = $lineStockOptions->firstWhere('id', (int) $selectedStockPalletId)
                                ?? $dispatchLine?->stockPallet
                                ?? $line->stockPallet;
                            $selectedStockUnits = $selectedStockPallet ? (int) $selectedStockPallet->quantity_units : 0;
                            $selectedStockPeaks = $selectedStockPallet
                                ? collect(range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS))
                                    ->map(fn ($peakIndex) => (int) ($selectedStockPallet->{'peak_'.$peakIndex} ?? 0))
                                    ->filter(fn ($peakUnits) => $peakUnits > 0)
                                    ->values()
                                : collect();
                            $isConfirmed = $dispatchLine?->confirmed_at !== null;
                            $hasDifference = $isConfirmed && $dispatchLine->hasLoadingDifference();
                            $isOverLoaded = $requestedUnits > 0 && $loadedUnits > $requestedUnits;
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $line->item?->sku ?? 'Artículo eliminado' }}</strong>
                                <span class="wms-line-type-pill wms-line-type-pill--{{ $line->lineType() }}">{{ $line->lineTypeLabel() }}</span>
                                @if ($dispatchLine)
                                    <input type="hidden" name="lines[{{ $lineKey }}][line_id]" value="{{ $dispatchLine->id }}">
                                    <input type="hidden" name="lines[{{ $lineKey }}][item_id]" value="{{ $dispatchLine->item_id }}">
                                    <input type="hidden" name="lines[{{ $lineKey }}][line_type]" value="{{ $dispatchLine->lineType() }}">
                                    <input type="hidden" name="lines[{{ $lineKey }}][stock_peak_index]" value="{{ $dispatchLine->stock_peak_index }}">
                                    <input type="hidden" name="lines[{{ $lineKey }}][loaded_quantity]" value="{{ $dispatchLine->loadedQuantity() }}">
                                    <input type="hidden" name="lines[{{ $lineKey }}][remove]" value="0">
                                @endif
                            </td>
                            <td>{{ $line->item?->description ?? 'Sin descripción' }}</td>
                            <td>
                                <span class="warehouse-request-stock-meta">{{ $selectedStockPallet?->lot ?: $line->lot ?: '-' }}</span>
                                <small>{{ $selectedStockPallet?->location_text ?: $line->stockPallet?->location_text ?: 'Sin ubicación' }}</small>
                            </td>
                            <td>
                                <span>{{ number_format($line->requestedPalletsCount(), 0, ',', '.') }} pallets</span>
                                <small>{{ number_format($line->requestedPeaksCount(), 0, ',', '.') }} picos · {{ number_format($requestedUnits, 0, ',', '.') }} uds</small>
                            </td>
                            <td>
                                @if ($selectedStockPallet)
                                    <span>{{ number_format((int) $selectedStockPallet->full_pallets, 0, ',', '.') }} pallets · {{ number_format($selectedStockUnits, 0, ',', '.') }} uds</span>
                                    <small>{{ $selectedStockPeaks->isNotEmpty() ? 'Picos: '.$selectedStockPeaks->map(fn ($peakUnits) => number_format($peakUnits, 0, ',', '.'))->implode(', ') : 'Sin picos abiertos' }}</small>
                                @else
                                    <span>Sin stock asignado</span>
                                    <small>Selecciona una partida para picos</small>
                                @endif
                            </td>
                            <td>
                                @if ($dispatchLine)
                                    <select
                                        name="lines[{{ $lineKey }}][stock_pallet_id]"
                                        class="auth-input warehouse-request-stock-select"
                                        aria-label="Partida de stock para {{ $dispatchLine->sku }}"
                                        @disabled(! $canEditLoading)
                                    >
                                        @if (! $selectedStockPalletId)
                                            <option value="">Stock FIFO</option>
                                        @endif
                                        @foreach ($lineStockOptions as $stockOption)
                                            <option value="{{ $stockOption->id }}" @selected((int) $selectedStockPalletId === (int) $stockOption->id)>
                                                {{ $stockOption->lot ?: 'Sin lote' }} · {{ $stockOption->location_text ?: 'Sin ubicación' }} · {{ number_format((int) $stockOption->full_pallets, 0, ',', '.') }} pal. · {{ number_format((int) $stockOption->quantity_units, 0, ',', '.') }} uds
                                            </option>
                                        @endforeach
                                    </select>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="warehouse-request-quantity-cell">
                                @if ($dispatchLine)
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        name="lines[{{ $lineKey }}][loaded_pallets]"
                                        value="{{ old('lines.'.$lineKey.'.loaded_pallets', $loadedPallets) }}"
                                        class="auth-input warehouse-request-quantity-input"
                                        aria-label="Pallets cargados para {{ $dispatchLine->sku }}"
                                        @disabled(! $canEditLoading)
                                    >
                                @else
                                    -
                                @endif
                            </td>
                            <td class="warehouse-request-quantity-cell">
                                @if ($dispatchLine)
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        name="lines[{{ $lineKey }}][loaded_partial_units]"
                                        value="{{ old('lines.'.$lineKey.'.loaded_partial_units', $loadedPartialUnits) }}"
                                        class="auth-input warehouse-request-quantity-input"
                                        aria-label="Unidades de pico cargadas para {{ $dispatchLine->sku }}"
                                        @disabled(! $canEditLoading)
                                    >
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                <span>{{ number_format($loadedUnits, 0, ',', '.') }} uds</span>
                                <small>{{ $dispatchLine?->loadedQuantityLabel() ?? 'Sin salida' }}</small>
                            </td>
                            <td>
                                @if (! $dispatchLine)
                                    <span class="warehouse-load-state warehouse-load-state--pending">Sin salida</span>
                                @elseif (! $isConfirmed)
                                    <span class="warehouse-load-state warehouse-load-state--pending">Sin confirmar</span>
                                @elseif ($isOverLoaded)
                                    <span class="warehouse-load-state warehouse-load-state--difference">Exceso</span>
                                @elseif ($hasDifference)
                                    <span class="warehouse-load-state warehouse-load-state--difference">Parcial</span>
                                @else
                                    <span class="warehouse-load-state warehouse-load-state--ok">OK</span>
                                @endif
                            </td>
                            <td>
                                @if ($dispatchLine)
                                    <input
                                        type="text"
                                        maxlength="250"
                                        name="lines[{{ $lineKey }}][loading_notes]"
                                        value="{{ old('lines.'.$lineKey.'.loading_notes', $dispatchLine->loading_notes) }}"
                                        class="auth-input warehouse-request-notes-input"
                                        placeholder="Opcional"
                                        aria-label="Observación de carga para {{ $dispatchLine->sku }}"
                                        @disabled(! $canEditLoading)
                                    >
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    @foreach ($dispatch?->lines?->where('is_extra_line', true) ?? collect() as $extraLine)
                        @php
                            $lineKey = 'line_'.$extraLine->id;
                            $isConfirmed = $extraLine->confirmed_at !== null;
                            $lineStockOptions = collect($stockOptionsByItem[$extraLine->item_id] ?? []);
                            $selectedStockPalletId = old('lines.'.$lineKey.'.stock_pallet_id', $extraLine->stock_pallet_id);
                            $selectedStockPallet = $lineStockOptions->firstWhere('id', (int) $selectedStockPalletId) ?? $extraLine->stockPallet;
                        @endphp
                        <tr class="warehouse-request-extra-line">
                            <td>
                                <strong>{{ $extraLine->sku }}</strong>
                                <span class="wms-line-type-pill wms-line-type-pill--{{ $extraLine->lineType() }}">Extra</span>
                                <input type="hidden" name="lines[{{ $lineKey }}][line_id]" value="{{ $extraLine->id }}">
                                <input type="hidden" name="lines[{{ $lineKey }}][item_id]" value="{{ $extraLine->item_id }}">
                                <input type="hidden" name="lines[{{ $lineKey }}][line_type]" value="{{ $extraLine->lineType() }}">
                                <input type="hidden" name="lines[{{ $lineKey }}][stock_peak_index]" value="{{ $extraLine->stock_peak_index }}">
                                <input type="hidden" name="lines[{{ $lineKey }}][loaded_quantity]" value="{{ $extraLine->loadedQuantity() }}">
                                <input type="hidden" name="lines[{{ $lineKey }}][remove]" value="0">
                            </td>
                            <td>{{ $extraLine->description }}</td>
                            <td>
                                <span class="warehouse-request-stock-meta">{{ $selectedStockPallet?->lot ?: $extraLine->lot ?: '-' }}</span>
                                <small>{{ $selectedStockPallet?->location_text ?: $extraLine->location_text ?: 'Sin ubicación' }}</small>
                            </td>
                            <td><span>Extra</span><small>0 uds solicitadas</small></td>
                            <td>
                                @if ($selectedStockPallet)
                                    <span>{{ number_format((int) $selectedStockPallet->full_pallets, 0, ',', '.') }} pallets · {{ number_format((int) $selectedStockPallet->quantity_units, 0, ',', '.') }} uds</span>
                                @else
                                    <span>Sin stock asignado</span>
                                @endif
                            </td>
                            <td>
                                <select name="lines[{{ $lineKey }}][stock_pallet_id]" class="auth-input warehouse-request-stock-select" @disabled(! $canEditLoading)>
                                    @foreach ($lineStockOptions as $stockOption)
                                        <option value="{{ $stockOption->id }}" @selected((int) $selectedStockPalletId === (int) $stockOption->id)>
                                            {{ $stockOption->lot ?: 'Sin lote' }} · {{ $stockOption->location_text ?: 'Sin ubicación' }} · {{ number_format((int) $stockOption->full_pallets, 0, ',', '.') }} pal. · {{ number_format((int) $stockOption->quantity_units, 0, ',', '.') }} uds
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="warehouse-request-quantity-cell">
                                <input type="number" min="0" step="1" name="lines[{{ $lineKey }}][loaded_pallets]" value="{{ old('lines.'.$lineKey.'.loaded_pallets', $extraLine->loadedPallets()) }}" class="auth-input warehouse-request-quantity-input" @disabled(! $canEditLoading)>
                            </td>
                            <td class="warehouse-request-quantity-cell">
                                <input type="number" min="0" step="1" name="lines[{{ $lineKey }}][loaded_partial_units]" value="{{ old('lines.'.$lineKey.'.loaded_partial_units', $extraLine->loadedPartialUnits()) }}" class="auth-input warehouse-request-quantity-input" @disabled(! $canEditLoading)>
                            </td>
                            <td>
                                <span>{{ number_format($extraLine->loadedUnitsTotal(), 0, ',', '.') }} uds</span>
                                <small>{{ $extraLine->loadedQuantityLabel() }}</small>
                            </td>
                            <td>
                                <span class="warehouse-load-state warehouse-load-state--{{ $isConfirmed ? 'ok' : 'pending' }}">
                                    {{ $isConfirmed ? 'Extra' : 'Sin confirmar' }}
                                </span>
                            </td>
                            <td>
                                <input type="text" maxlength="250" name="lines[{{ $lineKey }}][loading_notes]" value="{{ old('lines.'.$lineKey.'.loading_notes', $extraLine->loading_notes) }}" class="auth-input warehouse-request-notes-input" placeholder="Opcional" @disabled(! $canEditLoading)>
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
