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
        $selectedStockPallet = $stockPallets->firstWhere('id', old('stock_pallet_id', $filters['stock_pallet_id']));
        $singleStockPallet = $stockPallets->count() === 1 ? $stockPallets->first() : null;
        $summaryStockPallet = $selectedStockPallet ?? $singleStockPallet;
        $summaryPeakUnits = $summaryStockPallet
            ? collect(range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS))->sum(fn (int $peakNumber): int => (int) ($summaryStockPallet->{'peak_'.$peakNumber} ?? 0))
            : 0;
        $defaultUnitsPerPallet = old('units_per_pallet', $summaryStockPallet?->units_per_pallet ?: $selectedItem?->units_per_pallet ?: 1);
        $defaultAction = old('action', 'add');
        $defaultMode = old('mode', $summaryStockPallet ? 'existing' : 'new');
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
                                #{{ $stockPallet->id }} / {{ $stockPallet->lot ?: 'SIN LOTE' }} / {{ $stockPallet->pickingLocationLabel() ?? 'Sin ubicacion' }} / {{ number_format((int) $stockPallet->quantity_units, 0, ',', '.') }} uds
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
                            <p>Esta accion regulariza stock manualmente y quedara registrada.</p>
                        </div>
                    </div>

                    <div class="wms-adjustment-form-grid">
                        <label class="auth-field">
                            <span>Cliente</span>
                            <select name="client_id" class="auth-input" required>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->id }}" @selected((string) old('client_id', $filters['client_id']) === (string) $client->id)>
                                        {{ $client->name }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="auth-field">
                            <span>Referencia / articulo</span>
                            <select name="item_id" class="auth-input" required>
                                @foreach ($items as $item)
                                    <option value="{{ $item->id }}" @selected((string) old('item_id', $filters['item_id']) === (string) $item->id)>
                                        {{ $item->sku }} - {{ $item->description }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="auth-field">
                            <span>Accion</span>
                            <select name="action" class="auth-input" required>
                                <option value="add" @selected($defaultAction === 'add')>Anadir stock</option>
                                <option value="remove" @selected($defaultAction === 'remove')>Quitar stock</option>
                            </select>
                        </label>

                        <label class="auth-field">
                            <span>Modo</span>
                            <select name="mode" class="auth-input" required>
                                <option value="existing" @selected($defaultMode === 'existing')>Sobre partida existente</option>
                                <option value="new" @selected($defaultMode === 'new')>Crear nueva partida</option>
                            </select>
                        </label>

                        <label class="auth-field item-form-field--full">
                            <span>Partida existente</span>
                            <select name="stock_pallet_id" class="auth-input">
                                <option value="">Sin partida existente</option>
                                @foreach ($stockPallets as $stockPallet)
                                    @php
                                        $peakUnits = collect(range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS))
                                            ->sum(fn (int $peakNumber): int => (int) ($stockPallet->{'peak_'.$peakNumber} ?? 0));
                                    @endphp
                                    <option value="{{ $stockPallet->id }}" @selected((string) old('stock_pallet_id', $filters['stock_pallet_id'] ?? $singleStockPallet?->id) === (string) $stockPallet->id)>
                                        #{{ $stockPallet->id }} / Lote {{ $stockPallet->lot ?: 'SIN LOTE' }} / {{ $stockPallet->pickingLocationLabel() ?? 'Sin ubicacion' }} / {{ number_format((int) $stockPallet->full_pallets, 0, ',', '.') }} pallets / {{ number_format($peakUnits, 0, ',', '.') }} uds pico / {{ number_format((int) $stockPallet->quantity_units, 0, ',', '.') }} uds / {{ $stockPallet->stockCategoryLabel() }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="auth-field">
                            <span>Lote nueva partida</span>
                            <input type="text" name="lot" value="{{ old('lot', $summaryStockPallet?->lot ?: 'SIN LOTE') }}" class="auth-input" maxlength="100">
                        </label>

                        <label class="auth-field">
                            <span>Ubicacion nueva partida</span>
                            <select name="location_id" class="auth-input">
                                <option value="">Sin ubicacion</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" @selected((string) old('location_id', $summaryStockPallet?->location_id) === (string) $location->id)>
                                        {{ $location->displayLabel() }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="auth-field">
                            <span>Estado nueva partida</span>
                            <select name="status" class="auth-input" required>
                                @foreach ($statusOptions as $value => $label)
                                    <option value="{{ $value }}" @selected((string) old('status', $summaryStockPallet?->status ?? \App\Models\StockPallet::STATUS_AVAILABLE) === (string) $value)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="auth-field">
                            <span>Categoria nueva partida</span>
                            <select name="stock_category" class="auth-input" required>
                                @foreach ($categoryOptions as $value => $label)
                                    <option value="{{ $value }}" @selected((string) old('stock_category', $summaryStockPallet?->stock_category ?? $selectedItem?->stock_category ?? \App\Models\StockPallet::CATEGORY_IN_USE) === (string) $value)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="auth-field">
                            <span>Pallets completos</span>
                            <input type="number" name="full_pallets" value="{{ old('full_pallets', 0) }}" min="0" step="1" class="auth-input" required>
                        </label>

                        <label class="auth-field">
                            <span>Unidades por pallet</span>
                            <input type="number" name="units_per_pallet" value="{{ $defaultUnitsPerPallet }}" min="1" step="1" class="auth-input" required>
                        </label>

                        <label class="auth-field">
                            <span>Unidades pico</span>
                            <input type="number" name="peak_units" value="{{ old('peak_units', 0) }}" min="0" step="1" class="auth-input">
                        </label>

                        <label class="auth-field item-form-field--full">
                            <span>Nota interna opcional</span>
                            <textarea name="note" class="auth-input" rows="3" maxlength="1000" placeholder="Nota interna opcional.">{{ old('note') }}</textarea>
                        </label>
                    </div>

                    <div class="wms-adjustment-confirm">
                        <label class="wms-adjustment-check">
                            <input type="checkbox" name="confirmed" value="1" @checked(old('confirmed')) required>
                            <span>Confirmo regularizacion manual</span>
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
                                <dd>{{ $summaryStockPallet->lot ?: 'SIN LOTE' }}</dd>
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
                    <span>Lote {{ $movement->lot ?: 'SIN LOTE' }}</span>
                    <span>Final {{ number_format((int) $movement->units_after, 0, ',', '.') }} uds</span>
                </div>
            @empty
                <div class="wms-empty-state wms-adjustment-empty">
                    Todavia no hay regularizaciones registradas.
                </div>
            @endforelse
        </section>
    </div>
@endsection
