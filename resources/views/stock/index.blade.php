@extends('layouts.dashboard')

@section('title', 'Stock | MAXIMO WMS')
@section('topbar_title', 'Stock actual')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Stock</span>
        <span>/</span>
        <span>Inventario por partidas</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Inventario por partidas</h2>
            <span class="sr-only">Vista operativa agregada por lote y fecha de entrada</span>
            <span class="ops-page-meta">{{ $rows->count() }} filas en pantalla</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons ops-toolbar-links">
            <a href="{{ route('items.index') }}" class="button-secondary compact-button btn-compact">Articulos</a>
            <a href="{{ route('locations.index') }}" class="button-secondary compact-button btn-compact">Ubicaciones</a>
            @if (auth()->user()?->isSuperAdmin())
                <a href="{{ route('stock.import') }}" class="button-primary compact-button btn-compact">Importar stock</a>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="stock-summary stock-summary--single" aria-label="Resumen de stock">
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Total pallets</strong>
            <span>{{ number_format($summary['total_pallets'], 0, ',', '.') }}</span>
        </article>
    </section>

    <section class="surface-card stock-filter-card compact-card">
        <form method="GET" action="{{ route('stock.index') }}" class="stock-filters compact-filters filters-compact">
            <label class="auth-field">
                <span>Cliente</span>
                <select name="client_id" class="auth-input">
                    <option value="">Todos los clientes</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) $filters['client_id'] === (string) $client->id)>
                            {{ $client->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <div class="auth-field">
                <span>SKU o descripcion</span>
                <div
                    class="ajax-autocomplete"
                    data-ajax-autocomplete
                    data-endpoint="{{ $itemSearchEndpoint }}"
                    data-min-chars="2"
                    data-empty-message="Escribe al menos 2 caracteres para buscar referencias."
                    data-no-results-message="Sin resultados"
                    data-searching-message="Buscando..."
                    data-error-message="Error al buscar"
                    data-stock-item-filter
                >
                    <div class="ajax-autocomplete-control">
                        <input type="hidden" name="item_id" value="{{ $filters['item_id'] ?? '' }}" data-stock-item-id>
                        <input type="hidden" name="search" value="{{ $filters['search'] }}" data-stock-search-value>
                        <input
                            type="text"
                            value="{{ $filters['search'] }}"
                            class="auth-input"
                            placeholder="Buscar referencia"
                            autocomplete="off"
                            data-autocomplete-input
                        >
                        <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear hidden>Limpiar</button>
                    </div>
                    <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                        <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe al menos 2 caracteres...</div>
                        <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                    </div>
                </div>
            </div>

            <div class="auth-field">
                <span>Lote</span>
                <div
                    class="ajax-autocomplete"
                    data-ajax-autocomplete
                    data-endpoint="{{ $lotSearchEndpoint }}"
                    data-min-chars="2"
                    data-empty-message="Escribe al menos 2 caracteres para buscar lotes."
                    data-no-results-message="Sin resultados"
                    data-searching-message="Buscando..."
                    data-error-message="Error al buscar"
                    data-stock-lot-filter
                >
                    <div class="ajax-autocomplete-control">
                        <input type="hidden" name="lot" value="{{ $filters['lot'] }}" data-stock-lot-value>
                        <input
                            type="text"
                            value="{{ $filters['lot'] }}"
                            class="auth-input"
                            placeholder="Filtrar por lote"
                            autocomplete="off"
                            data-autocomplete-input
                        >
                        <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear hidden>Limpiar</button>
                    </div>
                    <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                        <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe al menos 2 caracteres...</div>
                        <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                    </div>
                </div>
            </div>

            <label class="auth-field">
                <span>Estado de stock</span>
                <select name="stock_state" class="auth-input">
                    <option value="with_stock" @selected($filters['stock_state'] === 'with_stock')>Con stock</option>
                    <option value="without_stock" @selected($filters['stock_state'] === 'without_stock')>Sin stock</option>
                    <option value="all" @selected($filters['stock_state'] === 'all')>Todos</option>
                </select>
            </label>

            <label class="auth-field">
                <span>Estado de partida</span>
                <select name="batch_status" class="auth-input">
                    <option value="all" @selected($filters['batch_status'] === 'all')>Todos</option>
                    <option value="available" @selected($filters['batch_status'] === 'available')>Disponible</option>
                    <option value="blocked" @selected($filters['batch_status'] === 'blocked')>Bloqueado</option>
                    <option value="obsolete" @selected($filters['batch_status'] === 'obsolete')>Obsoleto</option>
                </select>
            </label>

            <div class="auth-field">
                <span>Ubicacion</span>
                <div
                    class="ajax-autocomplete"
                    data-ajax-autocomplete
                    data-endpoint="{{ $locationSearchEndpoint }}"
                    data-min-chars="2"
                    data-empty-message="Escribe al menos 2 caracteres para buscar ubicaciones."
                    data-no-results-message="Sin resultados"
                    data-searching-message="Buscando..."
                    data-error-message="Error al buscar"
                    data-stock-location-filter
                >
                    <div class="ajax-autocomplete-control">
                        <input type="hidden" name="location_id" value="{{ $filters['location_id'] ?? '' }}" data-stock-location-id>
                        <input type="hidden" name="location" value="{{ $filters['location'] }}" data-stock-location-value>
                        <input
                            type="text"
                            value="{{ $filters['location'] }}"
                            class="auth-input"
                            placeholder="Buscar ubicacion"
                            autocomplete="off"
                            data-autocomplete-input
                        >
                        <button type="button" class="ajax-autocomplete-clear" data-autocomplete-clear hidden>Limpiar</button>
                    </div>
                    <div class="ajax-autocomplete-panel" data-autocomplete-panel hidden>
                        <div class="ajax-autocomplete-status" data-autocomplete-status>Escribe al menos 2 caracteres...</div>
                        <div class="ajax-autocomplete-list" data-autocomplete-list role="listbox"></div>
                    </div>
                </div>
            </div>

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('stock.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($rows->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin resultados</span>
            <h3>No hay partidas para estos filtros</h3>
            <p>Ajusta cliente, lote, estado o ubicacion para recuperar inventario o referencias sin stock.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card stock-desktop-table">
            <div class="data-table-wrap stock-table-wrap">
                <table class="data-table stock-table table-compact" aria-label="Vista operativa de stock por partidas">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Descripcion</th>
                            <th>Cantidad</th>
                            <th>Lote</th>
                            <th>Fecha entrada</th>
                            <th>Uds/pallet</th>
                            <th>Pallets</th>
                            <th>Picos</th>
                            <th>Pico 1</th>
                            <th>Pico 2</th>
                            <th>Pico 3</th>
                            <th>Pico 4</th>
                            <th>Pico 5</th>
                            <th>Pico 6</th>
                            <th>Pico 7</th>
                            <th>Pico 8</th>
                            <th>Pico 9</th>
                            <th>Pico 10</th>
                            <th>Estado articulo</th>
                            <th>Estado partida</th>
                            <th>Ubicacion</th>
                            <th>Ubicacion por defecto</th>
                            <th>Motivo bloqueo</th>
                            @if (auth()->user()?->isSuperAdmin())
                                <th>Acciones</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="stock-row stock-row--{{ $row['row_visual_state'] }}">
                                <td><strong>{{ $row['sku'] }}</strong></td>
                                <td>{{ $row['description'] }}</td>
                                <td class="stock-total">{{ number_format($row['quantity_units'], 0, ',', '.') }}</td>
                                <td>{{ $row['lot_label'] }}</td>
                                <td>{{ $row['received_at'] ?? '-' }}</td>
                                <td>{{ $row['units_per_pallet_label'] }}</td>
                                <td>{{ number_format($row['full_pallets'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['peaks_count'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['peak_1'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['peak_2'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['peak_3'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['peak_4'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['peak_5'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['peak_6'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['peak_7'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['peak_8'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['peak_9'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['peak_10'], 0, ',', '.') }}</td>
                                <td>
                                    <span class="status-badge item-status-badge item-status-badge--{{ $row['item_status'] }}">
                                        {{ $row['item_status_label'] }}
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge batch-status-badge{{ $row['batch_status'] ? ' batch-status-badge--'.$row['batch_status'] : '' }}">
                                        {{ $row['batch_status_label'] }}
                                    </span>
                                </td>
                                <td>{{ $row['location_label'] }}</td>
                                <td>{{ $row['default_location_label'] }}</td>
                                <td>{{ $row['blocked_reason'] ?: '-' }}</td>
                                @if (auth()->user()?->isSuperAdmin())
                                    <td>
                                        @if ($row['row_type'] === 'stock' && $row['item_id'])
                                            <a href="{{ route('stock.batches.edit', $row['id']) }}" class="button-secondary compact-button btn-compact">Editar</a>
                                        @else
                                            -
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="stock-mobile-list" aria-label="Vista movil de stock">
            @foreach ($rows as $row)
                <article class="surface-card stock-mobile-card compact-card stock-mobile-card--{{ $row['row_visual_state'] }}">
                    <div class="stock-cell-main">
                        <strong>{{ $row['sku'] }}</strong>
                        <span>{{ $row['lot_label'] }}</span>
                    </div>

                    <p>{{ $row['description'] }}</p>

                    <div class="stock-pill-list">
                        <span class="status-badge item-status-badge item-status-badge--{{ $row['item_status'] }}">
                            {{ $row['item_status_label'] }}
                        </span>
                        <span class="status-badge batch-status-badge{{ $row['batch_status'] ? ' batch-status-badge--'.$row['batch_status'] : '' }}">
                            {{ $row['batch_status_label'] }}
                        </span>
                    </div>

                    <div class="stock-mobile-metrics">
                        <div>
                            <span>Entrada</span>
                            <strong>{{ $row['received_at'] ?? '-' }}</strong>
                        </div>
                        <div>
                            <span>Ubicacion</span>
                            <strong>{{ $row['location_label'] }}</strong>
                        </div>
                        <div>
                            <span>Uds/pallet</span>
                            <strong>{{ $row['units_per_pallet_label'] }}</strong>
                        </div>
                        <div>
                            <span>Unidades</span>
                            <strong class="stock-total">{{ number_format($row['quantity_units'], 0, ',', '.') }}</strong>
                        </div>
                        <div>
                            <span>Pallets</span>
                            <strong>{{ number_format($row['full_pallets'], 0, ',', '.') }}</strong>
                        </div>
                        <div>
                            <span>Picos</span>
                            <strong>{{ number_format($row['peaks_count'], 0, ',', '.') }}</strong>
                        </div>
                    </div>

                    @if ($row['default_location_label'] !== 'Sin ubicacion por defecto')
                        <p class="users-table-email">Ubicacion por defecto: {{ $row['default_location_label'] }}</p>
                    @endif

                    @if ($row['blocked_reason'])
                        <p class="users-table-email">Bloqueo: {{ $row['blocked_reason'] }}</p>
                    @endif

                    @if (auth()->user()?->isSuperAdmin() && $row['row_type'] === 'stock' && $row['item_id'])
                        <div class="item-form-actions action-buttons">
                            <a href="{{ route('stock.batches.edit', $row['id']) }}" class="button-secondary compact-button btn-compact">Editar partida</a>
                        </div>
                    @endif
                </article>
            @endforeach
        </section>
    @endif

    <div class="dispatch-inline-help">
        Pendiente seleccionar partida/lote concreto en preparacion de salida cuando se active descuento real de stock.
    </div>
@endsection
