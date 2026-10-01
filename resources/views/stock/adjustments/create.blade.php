@extends('layouts.dashboard')

@section('title', 'Regularizar stock | MAXIMO WMS')
@section('topbar_title', 'Regularizar stock')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Stock', 'href' => route('stock.index')],
            ['label' => 'Regularizar'],
        ];
        $selectedClient = $clients->firstWhere('id', $filters['client_id']);
        $selectedItem = $items->firstWhere('id', $filters['item_id']);
        $isNewBatchRequest = (bool) ($filters['new_batch'] ?? false);
        $selectedStockPallet = $isNewBatchRequest ? null : $stockPallets->firstWhere('id', old('stock_pallet_id', $filters['stock_pallet_id']));
        $stockPalletsWithStock = $stockPallets->filter(fn ($stockPallet): bool => (int) $stockPallet->quantity_units > 0);
        $singleStockPallet = $stockPallets->count() === 1 ? $stockPallets->first() : null;
        $singleStockPalletWithStock = $stockPalletsWithStock->count() === 1 ? $stockPalletsWithStock->first() : null;
        $summaryStockPallet = $isNewBatchRequest ? null : ($selectedStockPallet ?? $singleStockPalletWithStock ?? $singleStockPallet);
        $summaryPeakValues = $summaryStockPallet
            ? collect(range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS))
                ->map(fn (int $peakNumber): int => (int) ($summaryStockPallet->{'peak_'.$peakNumber} ?? 0))
                ->filter(fn (int $peak): bool => $peak > 0)
                ->values()
            : collect();
        $summaryPeakUnits = $summaryPeakValues->sum();
        $defaultUnitsPerPallet = old('units_per_pallet', $summaryStockPallet?->units_per_pallet ?: $selectedItem?->units_per_pallet ?: 1);
        $defaultAction = old('action', 'add');
        $defaultMode = old('mode', $isNewBatchRequest ? 'new' : ($summaryStockPallet || $stockPallets->isNotEmpty() ? 'existing' : 'new'));
        $adjustmentPeaks = collect(old('peaks', []))
            ->filter(fn ($peak) => $peak !== null && $peak !== '')
            ->values()
            ->all();
        $maxPeakColumns = \App\Models\StockPallet::MAX_PEAK_COLUMNS;
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-detail-page wms-adjustment-page">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">{{ $errors->first() }}</div>
        @endif

        <section class="surface-card compact-card wms-detail-header wms-adjustment-header">
            <div class="wms-adjustment-title">
                <span>Stock</span>
                <h2>Regularizar stock</h2>
                <p>Alta o baja manual de stock por superadmin con registro automatico.</p>
            </div>

            <div class="wms-adjustment-safe-note">
                <strong>Registro obligatorio</strong>
                <span>No crea entradas, salidas ni albaranes. Solo ajusta stock y queda auditado.</span>
            </div>

            <div class="wms-detail-actions wms-adjustment-actions">
                <a href="{{ route('stock.index', $filters['client_id'] ? ['client_id' => $filters['client_id']] : []) }}" class="button-secondary compact-button btn-compact">Volver a stock</a>
                <a href="{{ route('traceability.movements.index') }}" class="button-secondary compact-button btn-compact">Movimientos</a>
            </div>
        </section>

        <section class="surface-card compact-card wms-adjustment-picker">
            <form method="GET" action="{{ route('stock.adjustments.create') }}" class="wms-adjustment-filter-form">
                <label class="auth-field">
                    <span>Cliente</span>
                    <select name="client_id" class="auth-input" required>
                        <option value="">Selecciona cliente</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((string) $filters['client_id'] === (string) $client->id)>
                                {{ $client->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="auth-field">
                    <span>Referencia / articulo</span>
                    <select name="item_id" class="auth-input" @disabled($filters['client_id'] === null) required>
                        <option value="">{{ $filters['client_id'] === null ? 'Selecciona primero cliente' : 'Selecciona referencia' }}</option>
                        @foreach ($items as $item)
                            <option value="{{ $item->id }}" @selected((string) $filters['item_id'] === (string) $item->id)>
                                {{ $item->sku }} - {{ $item->description }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="auth-field">
                    <span>Partida</span>
                    <select name="stock_pallet_id" class="auth-input" @disabled($filters['item_id'] === null)>
                        <option value="">Sin preseleccion</option>
                        @foreach ($stockPallets as $stockPallet)
                            <option value="{{ $stockPallet->id }}" @selected((string) $filters['stock_pallet_id'] === (string) $stockPallet->id)>
                                #{{ $stockPallet->id }} / {{ $stockPallet->lot ?: 'NO LOTE' }} / {{ $stockPallet->pickingLocationLabel() ?? 'Sin ubicacion' }} / {{ number_format((int) $stockPallet->quantity_units, 0, ',', '.') }} uds
                            </option>
                        @endforeach
                    </select>
                </label>

                <div class="wms-adjustment-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Mostrar seleccion</button>
                    <a href="{{ route('stock.adjustments.create') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                </div>
            </form>
        </section>

        @if ($filters['client_id'] !== null && $filters['item_id'] !== null)
            <form method="POST" action="{{ route('stock.adjustments.store') }}" class="wms-adjustment-workflow">
                @csrf

                <section class="surface-card compact-card wms-adjustment-summary" aria-label="Resumen antes de confirmar">
                    <div>
                        <span>Cliente</span>
                        <strong>{{ $selectedClient?->name ?? 'Cliente seleccionado' }}</strong>
                    </div>
                    <div>
                        <span>Referencia</span>
                        <strong>{{ $selectedItem?->sku ?? 'Referencia seleccionada' }}</strong>
                        <small>{{ $selectedItem?->description }}</small>
                    </div>
                    <div>
                        <span>Stock actual</span>
                        <strong>{{ $summaryStockPallet ? number_format((int) $summaryStockPallet->quantity_units, 0, ',', '.').' uds' : 'Nueva partida' }}</strong>
                        <small>{{ $summaryStockPallet ? number_format((int) $summaryStockPallet->full_pallets, 0, ',', '.').' pallets / '.number_format($summaryPeakUnits, 0, ',', '.').' uds pico' : 'Se creara si anades stock' }}</small>
                    </div>
                    <div>
                        <span>Advertencia</span>
                        <strong>Queda registrado</strong>
                        <small>No crea entrada ni salida.</small>
                    </div>
                </section>

                <section class="surface-card compact-card wms-adjustment-form-card">
                    <div class="wms-section-head">
                        <div>
                            <strong>Datos de regularizacion</strong>
                            <p>Ajuste a aplicar: añade o quita palets completos y picos concretos. El stock actual no se sustituye.</p>
                        </div>
                    </div>

                    <div class="wms-adjustment-form-grid wms-adjustment-form-grid--workflow">
                        <input type="hidden" name="client_id" value="{{ old('client_id', $filters['client_id']) }}">
                        <input type="hidden" name="item_id" value="{{ old('item_id', $filters['item_id']) }}">

                        @if ($summaryStockPallet)
                            <input type="hidden" name="mode" value="existing" data-adjustment-mode>
                            <input type="hidden" name="stock_pallet_id" value="{{ $summaryStockPallet->id }}">
                            <input type="hidden" name="lot" value="{{ $summaryStockPallet->lot }}">
                            <input type="hidden" name="location_id" value="{{ $summaryStockPallet->location_id }}">
                            <input type="hidden" name="status" value="{{ $summaryStockPallet->status }}">
                            <input type="hidden" name="stock_category" value="{{ $summaryStockPallet->stock_category }}">

                            <article class="wms-adjustment-target item-form-field--full" aria-label="Partida que se regulariza">
                                <span>Vas a regularizar esta partida</span>
                                <strong>{{ $selectedItem?->sku }} · {{ number_format((int) $summaryStockPallet->full_pallets, 0, ',', '.') }} {{ (int) $summaryStockPallet->full_pallets === 1 ? 'palé' : 'palés' }}{{ $summaryStockPallet->peaks_count ? ' y '.number_format((int) $summaryStockPallet->peaks_count, 0, ',', '.').' picos' : '' }}</strong>
                                <small>Lote {{ $summaryStockPallet->lot ?: 'NO LOTE' }} · {{ $summaryStockPallet->pickingLocationLabel() ?? 'Sin ubicación' }} · {{ number_format((int) $summaryStockPallet->quantity_units, 0, ',', '.') }} uds disponibles</small>
                                <a href="{{ route('stock.adjustments.create', ['client_id' => $filters['client_id'], 'item_id' => $filters['item_id'], 'new_batch' => 1]) }}" class="wms-adjustment-new-batch-link">¿Necesitas crear una partida nueva?</a>
                            </article>
                        @else
                            <fieldset class="wms-adjustment-mode-choice item-form-field--full" data-adjustment-mode-choice>
                                <legend>¿Dónde quieres hacer el ajuste?</legend>
                                <label class="wms-adjustment-mode-option">
                                    <input type="radio" name="mode" value="existing" data-adjustment-mode @checked($defaultMode === 'existing')>
                                    <span>
                                        <strong>En una partida existente</strong>
                                        <small>Para añadir o quitar stock de un lote que ya está registrado.</small>
                                    </span>
                                </label>
                                <label class="wms-adjustment-mode-option">
                                    <input type="radio" name="mode" value="new" data-adjustment-mode @checked($defaultMode === 'new')>
                                    <span>
                                        <strong>Crear una partida nueva</strong>
                                        <small>Solo si ese stock todavía no existe en el sistema.</small>
                                    </span>
                                </label>
                            </fieldset>

                            <label class="auth-field item-form-field--full" data-adjustment-existing-fields>
                                <span>Partida que quieres regularizar</span>
                                <select name="stock_pallet_id" class="auth-input" data-adjustment-stock-pallet>
                                    <option value="">Selecciona la partida concreta</option>
                                    @foreach ($stockPallets as $stockPallet)
                                        @php
                                            $peakUnits = collect(range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS))
                                                ->sum(fn (int $peakNumber): int => (int) ($stockPallet->{'peak_'.$peakNumber} ?? 0));
                                        @endphp
                                        <option value="{{ $stockPallet->id }}" @selected((string) old('stock_pallet_id', $filters['stock_pallet_id']) === (string) $stockPallet->id)>
                                            {{ number_format((int) $stockPallet->full_pallets, 0, ',', '.') }} palés · {{ number_format($peakUnits, 0, ',', '.') }} uds en picos · {{ number_format((int) $stockPallet->quantity_units, 0, ',', '.') }} uds · Lote {{ $stockPallet->lot ?: 'NO LOTE' }} · {{ $stockPallet->pickingLocationLabel() ?? 'Sin ubicación' }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="helper-text">Elige una partida para quitar stock. Si hay varias, puedes volver arriba y pulsar «Mostrar selección» para ver su detalle antes de confirmar.</small>
                            </label>

                            <div class="wms-adjustment-new-fields item-form-field--full" data-adjustment-new-fields>
                                <p class="wms-adjustment-new-fields-title">Datos de la nueva partida</p>
                                <div class="wms-adjustment-new-fields-grid">
                                    <label class="auth-field">
                                        <span>Lote</span>
                                        <input type="text" name="lot" value="{{ old('lot', 'NO LOTE') }}" class="auth-input" maxlength="100">
                                    </label>

                                    <label class="auth-field">
                                        <span>Ubicación</span>
                                        <select name="location_id" class="auth-input">
                                            <option value="">Sin ubicación</option>
                                            @foreach ($locations as $location)
                                                <option value="{{ $location->id }}" @selected((string) old('location_id') === (string) $location->id)>
                                                    {{ $location->displayLabel() }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="auth-field">
                                        <span>Estado</span>
                                        <select name="status" class="auth-input">
                                            @foreach ($statusOptions as $value => $label)
                                                <option value="{{ $value }}" @selected((string) old('status', \App\Models\StockPallet::STATUS_AVAILABLE) === (string) $value)>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="auth-field">
                                        <span>Categoría</span>
                                        <select name="stock_category" class="auth-input">
                                            @foreach ($categoryOptions as $value => $label)
                                                <option value="{{ $value }}" @selected((string) old('stock_category', $selectedItem?->stock_category ?? \App\Models\StockPallet::CATEGORY_IN_USE) === (string) $value)>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>
                            </div>
                        @endif

                        <fieldset class="wms-adjustment-action-choice item-form-field--full">
                            <legend>¿Qué quieres regularizar?</legend>
                            <label class="wms-adjustment-action-option wms-adjustment-action-option--add">
                                <input type="radio" name="action" value="add" data-adjustment-action @checked($defaultAction === 'add') required>
                                <span>
                                    <strong>Añadir stock</strong>
                                    <small>El palé o pico existe físicamente y falta en el sistema.</small>
                                </span>
                            </label>
                            <label class="wms-adjustment-action-option wms-adjustment-action-option--remove">
                                <input type="radio" name="action" value="remove" data-adjustment-action @checked($defaultAction === 'remove')>
                                <span>
                                    <strong>Quitar stock</strong>
                                    <small>El palé o pico ya no está físicamente en esta partida.</small>
                                </span>
                            </label>
                        </fieldset>

                        <section class="wms-adjustment-breakdown item-form-field--full" data-adjustment-breakdown>
                            <div class="wms-adjustment-breakdown-head">
                                <div>
                                    <strong>¿Cuánto stock quieres ajustar?</strong>
                                    <p>Un palé completo usa automáticamente su cantidad habitual. Añade un pico solo si es un palé parcial.</p>
                                </div>
                                <div class="wms-adjustment-total" aria-live="polite">
                                    <span>Total calculado</span>
                                    <strong><span data-adjustment-total>0</span> uds</strong>
                                    <small>Diferencia a aplicar: <span data-adjustment-difference>+0</span> uds</small>
                                </div>
                            </div>

                            <div class="wms-adjustment-breakdown-grid">
                                <label class="auth-field">
                                    <span data-adjustment-pallet-label>Palés completos a añadir</span>
                                    <input type="number" name="full_pallets" value="{{ old('full_pallets', 0) }}" min="0" step="1" class="auth-input" data-adjustment-pallets required>
                                </label>

                                <label class="auth-field">
                                    <span>Unidades por palé</span>
                                    <input type="number" name="units_per_pallet" value="{{ $defaultUnitsPerPallet }}" min="1" step="1" class="auth-input" data-adjustment-units-per-pallet required @readonly($summaryStockPallet)>
                                </label>

                                <div class="wms-adjustment-total-detail">
                                    <span>Palets almacén</span>
                                    <strong><span data-adjustment-pallet-total>0</span></strong>
                                    <small><span data-adjustment-peak-count>0</span> <span data-adjustment-peak-label>picos en el ajuste</span></small>
                                </div>
                            </div>

                            <div class="wms-adjustment-peaks" data-adjustment-peaks data-initial-peaks='@json($adjustmentPeaks)'>
                                <div class="wms-adjustment-peaks-head">
                                    <div>
                                        <strong>Picos</strong>
                                        <span>Unidades de cada palé parcial</span>
                                    </div>
                                    <button type="button" class="button-secondary compact-button btn-compact" data-add-peak>Añadir pico</button>
                                </div>
                                <div class="wms-adjustment-peak-list" data-peak-list></div>
                                <p class="helper-text" data-peak-empty>Sin picos en este ajuste.</p>
                            </div>
                        </section>

                        <label class="auth-field item-form-field--full">
                            <span>Motivo de regularización</span>
                            <textarea name="note" class="auth-input" rows="3" maxlength="1000" placeholder="Indica el motivo operativo de este ajuste." required>{{ old('note') }}</textarea>
                        </label>
                    </div>

                    <div class="wms-adjustment-confirm">
                        <label class="wms-adjustment-check">
                            <input type="checkbox" name="confirmed" value="1" @checked(old('confirmed')) required>
                            <span>Confirmo el ajuste manual indicado</span>
                        </label>
                        <button type="submit" class="button-primary compact-button btn-compact">Aplicar regularizacion</button>
                    </div>
                </section>

                <section class="surface-card compact-card wms-adjustment-selected" aria-label="Partida seleccionada">
                    <div class="wms-section-head">
                        <div>
                            <strong>Partida seleccionada</strong>
                            <p>{{ $summaryStockPallet ? 'Se conservaran cliente, articulo, lote, ubicacion, estado y categoria si ajustas esta partida.' : 'Puedes crear una partida nueva cuando la accion sea anadir stock.' }}</p>
                        </div>
                    </div>

                    @if ($summaryStockPallet)
                        <dl class="wms-adjustment-selected-grid">
                            <div>
                                <dt>Partida</dt>
                                <dd>#{{ $summaryStockPallet->id }}</dd>
                            </div>
                            <div>
                                <dt>Lote</dt>
                                <dd>{{ $summaryStockPallet->lot ?: 'NO LOTE' }}</dd>
                            </div>
                            <div>
                                <dt>Ubicacion</dt>
                                <dd>{{ $summaryStockPallet->pickingLocationLabel() ?? 'Sin ubicacion registrada' }}</dd>
                            </div>
                            <div>
                                <dt>Estado / categoria</dt>
                                <dd>{{ $summaryStockPallet->statusLabel() }} / {{ $summaryStockPallet->stockCategoryLabel() }}</dd>
                            </div>
                            <div>
                                <dt>Cantidad</dt>
                                <dd>{{ number_format((int) $summaryStockPallet->quantity_units, 0, ',', '.') }} uds</dd>
                            </div>
                            <div>
                                <dt>Pallets</dt>
                                <dd>{{ number_format((int) $summaryStockPallet->full_pallets, 0, ',', '.') }}</dd>
                            </div>
                            <div>
                                <dt>Picos</dt>
                                <dd>{{ number_format((int) $summaryStockPallet->peaks_count, 0, ',', '.') }} / {{ number_format($summaryPeakUnits, 0, ',', '.') }} uds pico</dd>
                            </div>
                            <div>
                                <dt>Detalle de picos</dt>
                                <dd>{{ $summaryPeakValues->isNotEmpty() ? $summaryPeakValues->map(fn (int $peak): string => number_format($peak, 0, ',', '.'))->implode(' · ').' uds' : 'Sin picos' }}</dd>
                            </div>
                            <div>
                                <dt>Uds/pallet</dt>
                                <dd>{{ number_format((int) $summaryStockPallet->units_per_pallet, 0, ',', '.') }}</dd>
                            </div>
                        </dl>
                    @else
                        <div class="wms-empty-state wms-adjustment-empty">
                            No hay partida preseleccionada. Para quitar stock debes seleccionar una partida existente.
                        </div>
                    @endif
                </section>
            </form>
        @else
            <section class="surface-card compact-card wms-empty-state wms-adjustment-empty">
                Selecciona cliente y referencia para regularizar stock.
            </section>
        @endif

        <section class="surface-card compact-card wms-adjustment-history">
            <div class="wms-section-head">
                <div>
                    <strong>Ultimas regularizaciones</strong>
                    <p>Historial visible solo para superadmin.</p>
                </div>
            </div>

            @forelse ($recentAdjustments as $movement)
                <div class="wms-adjustment-history-row">
                    <span>{{ $movement->effective_at?->format('d/m/Y H:i') ?? $movement->created_at?->format('d/m/Y H:i') }}</span>
                    <strong>{{ $movement->user_name ?? 'Usuario' }}</strong>
                    <span>{{ $movement->client_name ?? 'Cliente' }}</span>
                    <span>{{ $movement->sku ?? 'Referencia' }}</span>
                    <span>{{ ($movement->metadata['action'] ?? '') === 'remove' ? 'Quitar' : 'Anadir' }}</span>
                    <span>{{ number_format((int) $movement->units_delta, 0, ',', '.') }} uds</span>
                    <span>Lote {{ $movement->lot ?: 'NO LOTE' }}</span>
                    <span>Final {{ number_format((int) $movement->units_after, 0, ',', '.') }} uds</span>
                </div>
            @empty
                <div class="wms-empty-state wms-adjustment-empty">
                    Todavia no hay regularizaciones registradas.
                </div>
            @endforelse
        </section>
    </div>

    <script>
        (() => {
            const breakdown = document.querySelector('[data-adjustment-breakdown]');

            if (!breakdown) return;

            const form = breakdown.closest('form');
            const pallets = breakdown.querySelector('[data-adjustment-pallets]');
            const unitsPerPallet = breakdown.querySelector('[data-adjustment-units-per-pallet]');
            const peakList = breakdown.querySelector('[data-peak-list]');
            const peaks = breakdown.querySelector('[data-adjustment-peaks]');
            const emptyState = breakdown.querySelector('[data-peak-empty]');
            const total = breakdown.querySelector('[data-adjustment-total]');
            const palletTotal = breakdown.querySelector('[data-adjustment-pallet-total]');
            const peakCount = breakdown.querySelector('[data-adjustment-peak-count]');
            const difference = breakdown.querySelector('[data-adjustment-difference]');
            const actionInputs = [...form.querySelectorAll('[data-adjustment-action]')];
            const modeInputs = [...form.querySelectorAll('[data-adjustment-mode]')];
            const existingFields = [...form.querySelectorAll('[data-adjustment-existing-fields]')];
            const newFields = [...form.querySelectorAll('[data-adjustment-new-fields]')];
            const peakLabel = breakdown.querySelector('[data-adjustment-peak-label]');
            const palletLabel = breakdown.querySelector('[data-adjustment-pallet-label]');
            const format = new Intl.NumberFormat('es-ES');
            const maxPeaks = {{ $maxPeakColumns }};

            const selectedAction = () => actionInputs.find((input) => input.checked)?.value ?? 'add';
            const selectedMode = () => modeInputs.find((input) => input.checked)?.value ?? 'existing';

            const setFieldsVisible = (fields, visible) => {
                fields.forEach((field) => {
                    field.hidden = !visible;
                    field.querySelectorAll('input, select, textarea').forEach((input) => {
                        input.disabled = !visible;
                    });
                });
            };

            const syncWorkflow = () => {
                const mode = selectedMode();
                const isExisting = mode === 'existing';

                if (selectedAction() === 'remove' && !isExisting) {
                    const existingMode = modeInputs.find((input) => input.value === 'existing');

                    if (existingMode) {
                        existingMode.checked = true;
                    }
                }

                const finalMode = selectedMode();
                setFieldsVisible(existingFields, finalMode === 'existing');
                setFieldsVisible(newFields, finalMode === 'new');

                if (finalMode === 'new' && selectedAction() === 'remove') {
                    const addAction = actionInputs.find((input) => input.value === 'add');

                    if (addAction) {
                        addAction.checked = true;
                    }
                }

                form.querySelectorAll('[data-adjustment-mode-choice] label, .wms-adjustment-action-option').forEach((option) => {
                    const input = option.querySelector('input');
                    option.classList.toggle('is-selected', Boolean(input?.checked));
                });
            };

            const recalculate = () => {
                const fullPallets = Math.max(0, Number.parseInt(pallets.value, 10) || 0);
                const perPallet = Math.max(0, Number.parseInt(unitsPerPallet.value, 10) || 0);
                const peakValues = [...peakList.querySelectorAll('[data-peak-input]')]
                    .map((input) => Math.max(0, Number.parseInt(input.value, 10) || 0));
                const calculated = (fullPallets * perPallet) + peakValues.reduce((sum, value) => sum + value, 0);

                total.textContent = format.format(calculated);
                palletTotal.textContent = format.format(fullPallets);
                peakCount.textContent = format.format(peakValues.length);
                difference.textContent = `${selectedAction() === 'remove' ? '-' : '+'}${format.format(calculated)}`;
                peakLabel.textContent = selectedAction() === 'remove' ? 'picos a quitar' : 'picos en el ajuste';
                palletLabel.textContent = selectedAction() === 'remove' ? 'Palés completos a quitar' : 'Palés completos a añadir';
                emptyState.hidden = peakValues.length > 0;
            };

            const addPeak = (value = '') => {
                if (peakList.children.length >= maxPeaks) return;

                const row = document.createElement('div');
                row.className = 'wms-adjustment-peak-row';
                row.innerHTML = `<label class="auth-field"><span>Pico ${peakList.children.length + 1}</span><input type="number" name="peaks[]" value="${value}" min="1" step="1" class="auth-input" data-peak-input required></label><button type="button" class="button-secondary compact-button btn-compact" data-remove-peak aria-label="Quitar pico">Quitar</button>`;
                peakList.append(row);
                row.querySelector('[data-peak-input]').addEventListener('input', recalculate);
                row.querySelector('[data-remove-peak]').addEventListener('click', () => {
                    row.remove();
                    [...peakList.children].forEach((peakRow, index) => peakRow.querySelector('label span').textContent = `Pico ${index + 1}`);
                    recalculate();
                });
                recalculate();
            };

            JSON.parse(peaks.dataset.initialPeaks || '[]').forEach(addPeak);
            breakdown.querySelector('[data-add-peak]').addEventListener('click', () => addPeak());
            pallets.addEventListener('input', recalculate);
            unitsPerPallet.addEventListener('input', recalculate);
            actionInputs.forEach((input) => input.addEventListener('change', () => {
                syncWorkflow();
                recalculate();
            }));
            modeInputs.forEach((input) => input.addEventListener('change', () => {
                syncWorkflow();
                recalculate();
            }));
            syncWorkflow();
            recalculate();
        })();
    </script>
@endsection
