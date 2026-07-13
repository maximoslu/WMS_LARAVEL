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
                    <button type="submit" class="button-primary compact-button btn-compact">Empezar carga</button>
                </form>
            @elseif ($dispatch)
                <a href="{{ route('dispatches.show', $dispatch) }}" class="button-secondary compact-button btn-compact">Ver salida técnica</a>
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
            <form method="POST" action="{{ route('dispatches.confirm-loading', $dispatch) }}" class="warehouse-request-preparation-form" data-warehouse-request-allocations>
                @csrf
                @method('PATCH')
                <input type="hidden" name="return_to_request" value="1">
        @endif

        <div class="warehouse-request-line-list">
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
                    $loadedUnits = $dispatchLine?->loadedUnitsTotal() ?? 0;
                    $lineStockOptions = collect($stockOptionsByItem[$line->item_id] ?? []);
                    $lineAllocations = $dispatchLine?->allocations ?? collect();

                    if ($dispatchLine && $lineAllocations->isEmpty()) {
                        $fallbackStock = $dispatchLine->stockPallet ?? $line->stockPallet;
                        $lineAllocations = collect([(object) [
                            'stock_pallet_id' => $fallbackStock?->id,
                            'loaded_pallets' => $dispatchLine->loadedPallets(),
                            'loaded_partial_units' => $dispatchLine->loadedPartialUnits(),
                            'selected_peaks' => $dispatchLine->stock_peak_index ? [['index' => $dispatchLine->stock_peak_index, 'units' => $dispatchLine->units_per_peak]] : [],
                            'lot' => $fallbackStock?->lot,
                            'location_text' => $fallbackStock?->location_text,
                        ]]);
                    }

                    if ($dispatchLine && $lineAllocations->isEmpty()) {
                        $lineAllocations = collect([(object) [
                            'stock_pallet_id' => $lineStockOptions->first()?->id,
                            'loaded_pallets' => 0,
                            'loaded_partial_units' => 0,
                            'selected_peaks' => [],
                            'lot' => null,
                            'location_text' => null,
                        ]]);
                    }

                    $stateClass = 'pending';
                    $stateLabel = 'Sin preparar';
                    if ($dispatchLine?->confirmed_at !== null) {
                        $stateClass = $dispatchLine->loadingStatus() === 'complete'
                            ? 'ok'
                            : $dispatchLine->loadingStatus();
                        $stateLabel = $dispatchLine->loadingStatusLabel();
                    }
                    $unitDifference = $loadedUnits - $requestedUnits;
                    $differenceLabel = $unitDifference > 0 ? 'Exceso operativo' : 'Pendiente';
                @endphp

                <article class="warehouse-prep-line" data-prep-line data-requested-units="{{ $requestedUnits }}" data-units-per-pallet="{{ $dispatchLine?->units_per_pallet ?? $line->units_per_pallet ?? 0 }}">
                    <header class="warehouse-prep-line-head">
                        <div>
                            <strong>{{ $line->item?->sku ?? 'Artículo eliminado' }}</strong>
                            <span class="wms-line-type-pill wms-line-type-pill--{{ $line->lineType() }}">{{ $line->lineTypeLabel() }}</span>
                            <p>{{ $line->item?->description ?? 'Sin descripción' }}</p>
                        </div>
                        <span class="warehouse-load-state warehouse-load-state--{{ $stateClass }}" data-prep-state>{{ $stateLabel }}</span>
                    </header>

                    <div class="warehouse-prep-line-grid">
                        <aside class="warehouse-prep-summary">
                            <dl>
                                <div>
                                    <dt>Solicitado</dt>
                                    <dd>{{ number_format($line->requestedPalletsCount(), 0, ',', '.') }} pallets · {{ number_format($line->requestedPeaksCount(), 0, ',', '.') }} picos</dd>
                                </div>
                                <div>
                                    <dt>Unidades solicitadas</dt>
                                    <dd>{{ number_format($requestedUnits, 0, ',', '.') }} uds</dd>
                                </div>
                                <div>
                                    <dt>Cargado</dt>
                                    <dd><span data-loaded-units>{{ number_format($loadedUnits, 0, ',', '.') }}</span> uds</dd>
                                </div>
                                <div>
                                    <dt data-difference-label>{{ $differenceLabel }}</dt>
                                    <dd><span data-difference-units>{{ number_format(abs($unitDifference), 0, ',', '.') }}</span> uds</dd>
                                </div>
                            </dl>
                        </aside>

                        <div class="warehouse-prep-assignments" data-assignment-list>
                            @if ($dispatchLine)
                                <input type="hidden" name="lines[{{ $lineKey }}][line_id]" value="{{ $dispatchLine->id }}">
                                <input type="hidden" name="lines[{{ $lineKey }}][item_id]" value="{{ $dispatchLine->item_id }}">
                                <input type="hidden" name="lines[{{ $lineKey }}][line_type]" value="{{ $dispatchLine->lineType() }}">
                                <input type="hidden" name="lines[{{ $lineKey }}][loaded_quantity]" value="{{ $dispatchLine->loadedQuantity() }}">
                                <input type="hidden" name="lines[{{ $lineKey }}][loaded_pallets]" value="{{ $dispatchLine->loadedPallets() }}" data-line-loaded-pallets>
                                <input type="hidden" name="lines[{{ $lineKey }}][loaded_partial_units]" value="{{ $dispatchLine->loadedPartialUnits() }}" data-line-loaded-partial-units>
                                <input type="hidden" name="lines[{{ $lineKey }}][remove]" value="0">

                                @foreach ($lineAllocations as $allocationIndex => $allocation)
                                    @php
                                        $selectedStockPalletId = old('lines.'.$lineKey.'.allocations.'.$allocationIndex.'.stock_pallet_id', $allocation->stock_pallet_id);
                                        $selectedStockPallet = $lineStockOptions->firstWhere('id', (int) $selectedStockPalletId) ?? $allocation->stockPallet ?? null;
                                        $selectedPeakIndices = collect(old('lines.'.$lineKey.'.allocations.'.$allocationIndex.'.selected_peak_indices', collect($allocation->selected_peaks ?? [])->pluck('index')->all()))
                                            ->map(fn ($peakIndex) => (int) $peakIndex)
                                            ->all();
                                        $selectedPeakUnitsTotal = collect($allocation->selected_peaks ?? [])->sum(fn ($peak) => (int) ($peak['units'] ?? 0));
                                        $manualPartialUnits = max(0, (int) ($allocation->loaded_partial_units ?? 0) - $selectedPeakUnitsTotal);
                                    @endphp

                                    <div class="warehouse-prep-assignment" data-assignment>
                                        <div class="warehouse-prep-assignment-head">
                                            <strong>Asignación {{ $allocationIndex + 1 }}</strong>
                                            <button type="button" class="button-secondary compact-button btn-compact" data-remove-assignment @disabled(! $canEditLoading)>Quitar</button>
                                        </div>

                                        <label>
                                            <span>Partida / lote / ubicación</span>
                                            <select name="lines[{{ $lineKey }}][allocations][{{ $allocationIndex }}][stock_pallet_id]" class="auth-input" data-stock-select @disabled(! $canEditLoading)>
                                                <option value="">Selecciona partida</option>
                                                @foreach ($lineStockOptions as $stockOption)
                                                    @php
                                                        $stockPeaks = collect(range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS))
                                                            ->map(fn ($peakIndex) => (int) ($stockOption->{'peak_'.$peakIndex} ?? 0))
                                                            ->filter(fn ($peakUnits) => $peakUnits > 0)
                                                            ->values();
                                                    @endphp
                                                    <option value="{{ $stockOption->id }}" @selected((int) $selectedStockPalletId === (int) $stockOption->id)>
                                                        {{ $stockOption->lot ?: 'NO LOTE' }} · {{ $stockOption->location_text ?: 'Sin ubicación' }} · {{ number_format((int) $stockOption->full_pallets, 0, ',', '.') }} palets · picos: {{ $stockPeaks->isNotEmpty() ? $stockPeaks->implode(', ') : '0' }} · {{ number_format((int) $stockOption->quantity_units, 0, ',', '.') }} uds
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <div class="warehouse-prep-input-grid">
                                            <label>
                                                <span>Palets</span>
                                                <input type="number" min="0" step="1" name="lines[{{ $lineKey }}][allocations][{{ $allocationIndex }}][loaded_pallets]" value="{{ old('lines.'.$lineKey.'.allocations.'.$allocationIndex.'.loaded_pallets', $allocation->loaded_pallets ?? 0) }}" class="auth-input" data-loaded-pallets @disabled(! $canEditLoading)>
                                            </label>
                                            <label>
                                                <span>Pico uds manual</span>
                                                <input type="number" min="0" step="1" name="lines[{{ $lineKey }}][allocations][{{ $allocationIndex }}][loaded_partial_units]" value="{{ old('lines.'.$lineKey.'.allocations.'.$allocationIndex.'.loaded_partial_units', $manualPartialUnits) }}" class="auth-input" data-loaded-partial-units @disabled(! $canEditLoading)>
                                            </label>
                                            <div class="warehouse-prep-assignment-total">
                                                <span>Total asignación</span>
                                                <strong><span data-assignment-total>0</span> uds</strong>
                                            </div>
                                        </div>

                                        <div class="warehouse-prep-peaks" data-peak-groups>
                                            @foreach ($lineStockOptions as $stockOption)
                                                <div data-peak-group data-stock-id="{{ $stockOption->id }}" @if ((int) $selectedStockPalletId !== (int) $stockOption->id) hidden @endif>
                                                    <span>Picos existentes</span>
                                                    <div class="warehouse-prep-peak-chip-list">
                                                        @foreach (range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS) as $peakIndex)
                                                            @php $peakUnits = (int) ($stockOption->{'peak_'.$peakIndex} ?? 0); @endphp
                                                            @if ($peakUnits > 0)
                                                                <label class="warehouse-prep-peak-chip">
                                                                    <input type="checkbox" name="lines[{{ $lineKey }}][allocations][{{ $allocationIndex }}][selected_peak_indices][]" value="{{ $peakIndex }}" data-peak-units="{{ $peakUnits }}" @checked(in_array($peakIndex, $selectedPeakIndices, true)) @disabled(! $canEditLoading)>
                                                                    <span>{{ number_format($peakUnits, 0, ',', '.') }} uds</span>
                                                                </label>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach

                                <template data-assignment-template>
                                    @php
                                        $templateIndex = '__INDEX__';
                                    @endphp
                                    <div class="warehouse-prep-assignment" data-assignment>
                                        <div class="warehouse-prep-assignment-head">
                                            <strong>Nueva asignación</strong>
                                            <button type="button" class="button-secondary compact-button btn-compact" data-remove-assignment>Quitar</button>
                                        </div>
                                        <label>
                                            <span>Partida / lote / ubicación</span>
                                            <select name="lines[{{ $lineKey }}][allocations][{{ $templateIndex }}][stock_pallet_id]" class="auth-input" data-stock-select>
                                                <option value="">Selecciona partida</option>
                                                @foreach ($lineStockOptions as $stockOption)
                                                    @php
                                                        $stockPeaks = collect(range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS))
                                                            ->map(fn ($peakIndex) => (int) ($stockOption->{'peak_'.$peakIndex} ?? 0))
                                                            ->filter(fn ($peakUnits) => $peakUnits > 0)
                                                            ->values();
                                                    @endphp
                                                    <option value="{{ $stockOption->id }}">
                                                        {{ $stockOption->lot ?: 'NO LOTE' }} · {{ $stockOption->location_text ?: 'Sin ubicación' }} · {{ number_format((int) $stockOption->full_pallets, 0, ',', '.') }} palets · picos: {{ $stockPeaks->isNotEmpty() ? $stockPeaks->implode(', ') : '0' }} · {{ number_format((int) $stockOption->quantity_units, 0, ',', '.') }} uds
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <div class="warehouse-prep-input-grid">
                                            <label><span>Palets</span><input type="number" min="0" step="1" name="lines[{{ $lineKey }}][allocations][{{ $templateIndex }}][loaded_pallets]" value="0" class="auth-input" data-loaded-pallets></label>
                                            <label><span>Pico uds manual</span><input type="number" min="0" step="1" name="lines[{{ $lineKey }}][allocations][{{ $templateIndex }}][loaded_partial_units]" value="0" class="auth-input" data-loaded-partial-units></label>
                                            <div class="warehouse-prep-assignment-total"><span>Total asignación</span><strong><span data-assignment-total>0</span> uds</strong></div>
                                        </div>
                                        <div class="warehouse-prep-peaks" data-peak-groups>
                                            @foreach ($lineStockOptions as $stockOption)
                                                <div data-peak-group data-stock-id="{{ $stockOption->id }}" hidden>
                                                    <span>Picos existentes</span>
                                                    <div class="warehouse-prep-peak-chip-list">
                                                        @foreach (range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS) as $peakIndex)
                                                            @php $peakUnits = (int) ($stockOption->{'peak_'.$peakIndex} ?? 0); @endphp
                                                            @if ($peakUnits > 0)
                                                                <label class="warehouse-prep-peak-chip">
                                                                    <input type="checkbox" name="lines[{{ $lineKey }}][allocations][{{ $templateIndex }}][selected_peak_indices][]" value="{{ $peakIndex }}" data-peak-units="{{ $peakUnits }}">
                                                                    <span>{{ number_format($peakUnits, 0, ',', '.') }} uds</span>
                                                                </label>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </template>

                                <button type="button" class="button-secondary compact-button btn-compact warehouse-prep-add-assignment" data-add-assignment @disabled(! $canEditLoading)>+ Añadir otra partida</button>

                                <label class="warehouse-prep-notes">
                                    <span>Observación</span>
                                    <input type="text" maxlength="250" name="lines[{{ $lineKey }}][loading_notes]" value="{{ old('lines.'.$lineKey.'.loading_notes', $dispatchLine->loading_notes) }}" class="auth-input" placeholder="Opcional" @disabled(! $canEditLoading)>
                                </label>
                            @else
                                <div class="warehouse-request-inline-state">Genera la salida para registrar asignaciones de carga real.</div>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
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
