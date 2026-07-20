@extends('layouts.dashboard')

@section('title', 'Reubicar stock | MAXIMO WMS')
@section('topbar_title', 'Reubicar stock')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Stock', 'href' => route('stock.index')],
            ['label' => 'Reubicar'],
        ];
        $selectedClient = $clients->firstWhere('id', $filters['client_id']);
        $selectedItem = $items->firstWhere('id', $filters['item_id']);
        $selectedStockPallet = $stockPallets->firstWhere('id', old('stock_pallet_id', $filters['stock_pallet_id']));
        $selectedDestination = $locations->firstWhere('id', old('destination_location_id', $filters['destination_location_id']));
        $singleStockPallet = $stockPallets->count() === 1 ? $stockPallets->first() : null;
        $summaryStockPallet = $selectedStockPallet ?? $singleStockPallet;
        $summaryPeakUnits = $summaryStockPallet
            ? collect(range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS))->sum(fn (int $peakNumber): int => (int) ($summaryStockPallet->{'peak_'.$peakNumber} ?? 0))
            : 0;
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-detail-page wms-relocation-page">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">{{ $errors->first() }}</div>
        @endif

        <section class="surface-card compact-card wms-detail-header wms-relocation-header">
            <div class="wms-relocation-title">
                <span>Stock</span>
                <h2>Reubicar stock</h2>
                <p>Cambia la ubicacion fisica de una partida sin modificar cantidades.</p>
            </div>

            <div class="wms-relocation-safe-note">
                <strong>Accion controlada</strong>
                <span>Solo actualiza ubicacion. No descuenta stock, no crea entradas y no crea salidas.</span>
            </div>

            <div class="wms-detail-actions wms-relocation-actions">
                <a href="{{ route('stock.index', $filters['client_id'] ? ['client_id' => $filters['client_id']] : []) }}" class="button-secondary compact-button btn-compact">Volver a stock</a>
                <a href="{{ route('locations.index') }}" class="button-secondary compact-button btn-compact">Ubicaciones</a>
            </div>
        </section>

        <section class="surface-card compact-card wms-relocation-picker">
            <form method="GET" action="{{ route('stock.relocations.create') }}" class="wms-relocation-filter-form">
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

                <div class="wms-relocation-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Mostrar stock</button>
                    <a href="{{ route('stock.relocations.create') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                </div>
            </form>
        </section>

        @if ($filters['client_id'] !== null && $filters['item_id'] !== null)
            <form method="POST" action="{{ route('stock.relocations.store') }}" class="wms-relocation-workflow">
                @csrf
                <input type="hidden" name="client_id" value="{{ $filters['client_id'] }}">
                <input type="hidden" name="item_id" value="{{ $filters['item_id'] }}">

                <section class="surface-card compact-card wms-relocation-summary" aria-label="Resumen previo de reubicacion">
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
                        <span>Ubicacion actual</span>
                        <strong>{{ $summaryStockPallet?->pickingLocationLabel() ?? 'Selecciona una partida para ver su ubicacion actual.' }}</strong>
                    </div>
                    <div>
                        <span>Ubicacion destino</span>
                        <strong>{{ $selectedDestination?->displayLabel() ?? 'Pendiente de seleccionar' }}</strong>
                    </div>
                </section>

                <section class="surface-card compact-card wms-relocation-selected" aria-label="Partida seleccionada para reubicar">
                    <div class="wms-section-head">
                        <div>
                            <strong>Resumen de reubicacion</strong>
                            <p>{{ $summaryStockPallet ? 'Comprueba origen, destino y cantidad antes de reubicar.' : 'Selecciona una partida para ver su ubicacion actual.' }}</p>
                        </div>
                    </div>

                    @if ($summaryStockPallet)
                        <dl class="wms-relocation-selected-grid">
                            <div>
                                <dt>Cliente</dt>
                                <dd>{{ $selectedClient?->name ?? $summaryStockPallet->client?->name ?? 'Cliente seleccionado' }}</dd>
                            </div>
                            <div>
                                <dt>Referencia</dt>
                                <dd>{{ $summaryStockPallet->item?->sku }} - {{ $summaryStockPallet->item?->description }}</dd>
                            </div>
                            <div>
                                <dt>Partida concreta</dt>
                                <dd>#{{ $summaryStockPallet->id }}{{ $summaryStockPallet->pallet_code ? ' / '.$summaryStockPallet->pallet_code : '' }}</dd>
                            </div>
                            <div class="wms-relocation-selected-current">
                                <dt>Ubicacion actual</dt>
                                <dd>{{ $summaryStockPallet->pickingLocationLabel() ?? 'Sin ubicacion registrada' }}</dd>
                            </div>
                            <div>
                                <dt>Ubicacion destino</dt>
                                <dd>{{ $selectedDestination?->displayLabel() ?? 'Pendiente de seleccionar' }}</dd>
                            </div>
                            <div>
                                <dt>Cantidad</dt>
                                <dd>
                                    {{ number_format((int) $summaryStockPallet->full_pallets, 0, ',', '.') }} pallets /
                                    {{ number_format((int) $summaryStockPallet->peaks_count, 0, ',', '.') }} picos /
                                    {{ number_format($summaryPeakUnits, 0, ',', '.') }} uds pico /
                                    {{ number_format((int) $summaryStockPallet->quantity_units, 0, ',', '.') }} uds
                                </dd>
                            </div>
                            <div>
                                <dt>Lote</dt>
                                <dd>{{ $summaryStockPallet->lot ?: 'SIN LOTE' }}</dd>
                            </div>
                            <div>
                                <dt>Categoria</dt>
                                <dd>{{ $summaryStockPallet->stockCategoryLabel() }}</dd>
                            </div>
                        </dl>
                    @else
                        <div class="wms-empty-state wms-relocation-empty">
                            Selecciona una partida para ver su ubicacion actual.
                        </div>
                    @endif
                </section>

                <section class="surface-card compact-card wms-relocation-stock">
                    <div class="wms-section-head">
                        <div>
                            <strong>Partida a reubicar</strong>
                            <p>{{ $stockPallets->count() === 1 ? 'La referencia tiene una unica partida disponible.' : 'Selecciona la partida concreta. No se mueve todo el stock automaticamente.' }}</p>
                        </div>
                        <span>{{ $stockPallets->count() }} {{ $stockPallets->count() === 1 ? 'partida' : 'partidas' }}</span>
                    </div>

                    @forelse ($stockPallets as $stockPallet)
                        @php
                            $radioId = 'stock_pallet_'.$stockPallet->id;
                            $isSelected = (string) old('stock_pallet_id', $filters['stock_pallet_id'] ?? $singleStockPallet?->id) === (string) $stockPallet->id;
                            $peakUnits = collect(range(1, \App\Models\StockPallet::MAX_PEAK_COLUMNS))
                                ->sum(fn (int $peakNumber): int => (int) ($stockPallet->{'peak_'.$peakNumber} ?? 0));
                        @endphp

                        <label class="wms-relocation-batch" for="{{ $radioId }}">
                            <input id="{{ $radioId }}" type="radio" name="stock_pallet_id" value="{{ $stockPallet->id }}" @checked($isSelected) required>
                            <span class="wms-relocation-batch-main">
                                <strong>{{ $stockPallet->item?->sku }} / Ubicacion actual: {{ $stockPallet->pickingLocationLabel() ?? 'Sin ubicacion registrada' }}</strong>
                                <small>{{ $stockPallet->item?->description }}</small>
                            </span>
                            <span class="wms-relocation-batch-meta">
                                <span>Partida #{{ $stockPallet->id }}</span>
                                <span>Lote: {{ $stockPallet->lot ?: 'SIN LOTE' }}</span>
                                <span>{{ number_format((int) $stockPallet->full_pallets, 0, ',', '.') }} pallets</span>
                                <span>{{ number_format((int) $stockPallet->peaks_count, 0, ',', '.') }} picos</span>
                                <span>{{ number_format($peakUnits, 0, ',', '.') }} uds pico</span>
                                <span>{{ number_format((int) $stockPallet->quantity_units, 0, ',', '.') }} uds</span>
                                <span>{{ $stockPallet->stockCategoryLabel() }}</span>
                            </span>
                        </label>
                    @empty
                        <div class="wms-empty-state wms-relocation-empty">
                            No hay stock activo para reubicar en esta referencia.
                        </div>
                    @endforelse
                </section>

                <section class="surface-card compact-card wms-relocation-destination">
                    <div class="wms-section-head">
                        <div>
                            <strong>Nueva ubicacion</strong>
                            <p>Selecciona una ubicacion activa compatible con el cliente.</p>
                        </div>
                    </div>

                    <label class="auth-field">
                        <span>Ubicacion destino</span>
                        <select name="destination_location_id" class="auth-input" required>
                            <option value="">Selecciona ubicacion destino</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected((string) old('destination_location_id', $filters['destination_location_id']) === (string) $location->id)>
                                    {{ $location->displayLabel() }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <div class="wms-relocation-confirm">
                        <p>Esta accion no modifica cantidades ni descuenta stock.</p>
                        <button type="submit" class="button-primary compact-button btn-compact" @disabled($stockPallets->isEmpty())>
                            Reubicar
                        </button>
                    </div>
                </section>
            </form>
        @else
            <section class="surface-card compact-card wms-empty-state wms-relocation-empty">
                Selecciona cliente y referencia para ver sus partidas y confirmar una reubicacion.
            </section>
        @endif
    </div>
@endsection
