@extends('layouts.dashboard')

@section('title', 'Stock | MAXIMO WMS')
@section('topbar_title', 'Stock actual')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Stock</span>
        <span>/</span>
        <span>Inventario</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card wms-page-hero">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Stock actual</h2>
            <span class="ops-page-meta">Consulta existencias, lotes, pallets y picos operativos.</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons ops-toolbar-links">
            <a href="{{ route('items.index') }}" class="button-secondary compact-button btn-compact wms-action-secondary">Articulos</a>
            <a href="{{ route('locations.index') }}" class="button-secondary compact-button btn-compact wms-action-secondary">Ubicaciones</a>
            @if (auth()->user()?->isSuperAdmin())
                <a href="{{ route('stock.import') }}" class="button-primary compact-button btn-compact wms-action-primary">Importar stock</a>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="stock-summary" aria-label="Resumen de stock">
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Total referencias</strong>
            <span>{{ number_format($summary['references_with_stock'], 0, ',', '.') }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Total pallets</strong>
            <span>{{ number_format($summary['total_pallets'], 0, ',', '.') }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Total unidades</strong>
            <span>{{ number_format($summary['total_units'], 0, ',', '.') }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Partidas bloqueadas</strong>
            <span>{{ number_format($summary['blocked_batches'], 0, ',', '.') }}</span>
        </article>
    </section>

    <section class="surface-card stock-filter-card compact-card wms-filter-card">
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
                    <option value="all" @selected($filters['stock_state'] === 'all')>Todo</option>
                </select>
            </label>

            <label class="auth-field">
                <span>Estado</span>
                <select name="batch_status" class="auth-input">
                    <option value="all" @selected($filters['batch_status'] === 'all')>Todos</option>
                    <option value="available" @selected($filters['batch_status'] === 'available')>Disponibles</option>
                    <option value="blocked" @selected($filters['batch_status'] === 'blocked')>Bloqueados</option>
                    <option value="obsolete" @selected($filters['batch_status'] === 'obsolete')>Obsoletos</option>
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

            <label class="auth-field">
                <span>Picos</span>
                <select name="only_peaks" class="auth-input">
                    <option value="0" @selected(! $filters['only_peaks'])>Todos</option>
                    <option value="1" @selected($filters['only_peaks'])>Solo con picos</option>
                </select>
            </label>

            <label class="auth-field">
                <span>Por pagina</span>
                <select name="per_page" class="auth-input">
                    @foreach ([25, 50, 100] as $perPage)
                        <option value="{{ $perPage }}" @selected((int) $filters['per_page'] === $perPage)>{{ $perPage }}</option>
                    @endforeach
                </select>
            </label>

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact wms-action-primary">Filtrar</button>
                <a href="{{ route('stock.index') }}" class="button-secondary compact-button btn-compact wms-action-secondary">Limpiar</a>
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
            <div class="stock-table-header">
                <div class="stock-table-results">Mostrando {{ number_format($paginator->firstItem() ?? 0, 0, ',', '.') }}-{{ number_format($paginator->lastItem() ?? 0, 0, ',', '.') }} de {{ number_format($paginator->total(), 0, ',', '.') }} registros</div>
                @if ($paginator->hasPages())
                    <div class="stock-table-pagination stock-table-pagination--top">
                        {{ $paginator->links() }}
                    </div>
                @endif
            </div>

            <div class="data-table-wrap stock-table-wrap">
                <table class="data-table stock-table table-compact stock-table--master" aria-label="Vista operativa de stock por partidas">
                    <thead>
                        <tr>
                            <th class="stock-column-sticky">SKU</th>
                            <th>Descripcion</th>
                            <th>Lote</th>
                            <th class="stock-table-number">Cantidad</th>
                            <th class="stock-table-center">Pallets</th>
                            <th class="stock-table-center">Picos</th>
                            <th>Estado</th>
                            <th class="stock-table-center">Accion</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($detailId = 'stock-detail-'.$loop->index.'-'.($row['id'] ?? 'item-'.$row['item_id']))
                            @php($peakValues = collect(range(1, 10))->map(fn (int $peakNumber) => ['label' => 'Pico '.$peakNumber, 'value' => (int) ($row['peak_'.$peakNumber] ?? 0)]))
                            <tr class="stock-row stock-row--{{ $row['row_visual_state'] }}">
                                <td class="stock-column-sticky">
                                    <div class="stock-cell-main">
                                        <strong>{{ $row['sku'] }}</strong>
                                        <span>{{ $row['client_name'] }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="stock-description-cell">
                                        <strong>{{ $row['description'] }}</strong>
                                    </div>
                                </td>
                                <td>{{ $row['lot_label'] }}</td>
                                <td class="stock-total stock-table-number">{{ number_format($row['quantity_units'], 0, ',', '.') }}</td>
                                <td class="stock-table-center">{{ number_format($row['full_pallets'], 0, ',', '.') }}</td>
                                <td class="stock-table-center">
                                    @if ($row['peaks_count'] > 0)
                                        <span class="stock-peak-badge">{{ number_format($row['peaks_count'], 0, ',', '.') }} {{ $row['peaks_count'] === 1 ? 'pico' : 'picos' }}</span>
                                    @else
                                        <span class="stock-empty-badge">Sin picos</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="stock-status-stack">
                                        <span class="status-badge item-status-badge item-status-badge--{{ $row['item_status'] }}">
                                            {{ $row['item_status_label'] }}
                                        </span>
                                        <span class="status-badge batch-status-badge{{ $row['batch_status'] ? ' batch-status-badge--'.$row['batch_status'] : '' }}">
                                            {{ $row['batch_status_label'] }}
                                        </span>
                                    </div>
                                </td>
                                <td class="stock-table-center">
                                    <button
                                        type="button"
                                        class="button-secondary compact-button btn-table stock-detail-toggle"
                                        data-stock-detail-toggle
                                        data-target="{{ $detailId }}"
                                        aria-expanded="false"
                                        aria-controls="{{ $detailId }}"
                                    >
                                        Ver detalle
                                    </button>
                                </td>
                            </tr>
                            <tr id="{{ $detailId }}" class="stock-detail-row" data-stock-detail-row hidden>
                                <td colspan="8">
                                    <div class="stock-detail-panel">
                                        <div class="stock-detail-grid">
                                            <article class="stock-detail-card">
                                                <strong>Informacion logistica</strong>
                                                <dl class="stock-detail-list">
                                                    <div><dt>SKU</dt><dd>{{ $row['sku'] }}</dd></div>
                                                    <div><dt>Descripcion</dt><dd>{{ $row['description'] }}</dd></div>
                                                    <div><dt>Lote</dt><dd>{{ $row['lot_label'] }}</dd></div>
                                                    <div><dt>Fecha entrada</dt><dd>{{ $row['received_at'] ?? '-' }}</dd></div>
                                                    <div><dt>Uds/pallet</dt><dd>{{ $row['units_per_pallet_label'] }}</dd></div>
                                                    <div><dt>Cantidad total</dt><dd>{{ number_format($row['quantity_units'], 0, ',', '.') }}</dd></div>
                                                    <div><dt>Pallets</dt><dd>{{ number_format($row['full_pallets'], 0, ',', '.') }}</dd></div>
                                                    <div><dt>Picos total</dt><dd>{{ number_format($row['peaks_count'], 0, ',', '.') }}</dd></div>
                                                    <div><dt>Ubicacion</dt><dd>{{ $row['location_label'] }}</dd></div>
                                                    <div><dt>Ubicacion por defecto</dt><dd>{{ $row['default_location_label'] }}</dd></div>
                                                </dl>
                                            </article>

                                            <article class="stock-detail-card">
                                                <strong>Estados y contexto</strong>
                                                <div class="stock-detail-state-stack">
                                                    <span class="status-badge item-status-badge item-status-badge--{{ $row['item_status'] }}">
                                                        {{ $row['item_status_label'] }}
                                                    </span>
                                                    <span class="status-badge batch-status-badge{{ $row['batch_status'] ? ' batch-status-badge--'.$row['batch_status'] : '' }}">
                                                        {{ $row['batch_status_label'] }}
                                                    </span>
                                                </div>
                                                @if ($row['blocked_reason'])
                                                    <p class="stock-detail-note">Motivo bloqueo: {{ $row['blocked_reason'] }}</p>
                                                @endif
                                                @if (auth()->user()?->isSuperAdmin() && $row['row_type'] === 'stock' && $row['item_id'])
                                                    <div class="action-buttons">
                                                        <a href="{{ route('stock.batches.edit', $row['id']) }}" class="button-secondary compact-button btn-compact">Ver detalle</a>
                                                    </div>
                                                @endif
                                            </article>
                                        </div>

                                        <article class="stock-detail-card stock-detail-card--peaks">
                                            <strong>Distribucion de picos</strong>
                                            <div class="stock-peak-grid">
                                                @foreach ($peakValues as $peak)
                                                    <div class="stock-peak-card{{ $peak['value'] > 0 ? ' is-active' : '' }}">
                                                        <span>{{ $peak['label'] }}</span>
                                                        <strong>{{ number_format($peak['value'], 0, ',', '.') }}</strong>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </article>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($paginator->hasPages())
                <div class="stock-table-pagination">
                    {{ $paginator->links() }}
                </div>
            @endif
        </section>

        <section class="stock-mobile-list" aria-label="Vista movil de stock">
            @foreach ($rows as $row)
                @php($peakValues = collect(range(1, 10))->map(fn (int $peakNumber) => ['label' => 'Pico '.$peakNumber, 'value' => (int) ($row['peak_'.$peakNumber] ?? 0)]))
                <details class="surface-card stock-mobile-card compact-card stock-mobile-card--{{ $row['row_visual_state'] }}">
                    <summary class="stock-mobile-summary">
                        <div class="stock-cell-main">
                            <strong>{{ $row['sku'] }}</strong>
                            <span>{{ $row['lot_label'] }}</span>
                        </div>
                        <span class="stock-mobile-summary-meta">{{ number_format($row['quantity_units'], 0, ',', '.') }} uds</span>
                    </summary>

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
                            <span>Pallets</span>
                            <strong>{{ number_format($row['full_pallets'], 0, ',', '.') }}</strong>
                        </div>
                        <div>
                            <span>Picos total</span>
                            <strong>{{ number_format($row['peaks_count'], 0, ',', '.') }}</strong>
                        </div>
                    </div>

                    <div class="stock-peak-grid">
                        @foreach ($peakValues as $peak)
                            <div class="stock-peak-card{{ $peak['value'] > 0 ? ' is-active' : '' }}">
                                <span>{{ $peak['label'] }}</span>
                                <strong>{{ number_format($peak['value'], 0, ',', '.') }}</strong>
                            </div>
                        @endforeach
                    </div>

                    @if ($row['blocked_reason'])
                        <p class="users-table-email">Bloqueo: {{ $row['blocked_reason'] }}</p>
                    @endif

                    @if (auth()->user()?->isSuperAdmin() && $row['row_type'] === 'stock' && $row['item_id'])
                        <div class="item-form-actions action-buttons">
                            <a href="{{ route('stock.batches.edit', $row['id']) }}" class="button-secondary compact-button btn-compact">Ver detalle</a>
                        </div>
                    @endif
                </details>
            @endforeach

            @if ($paginator->hasPages())
                <div class="stock-table-pagination">
                    {{ $paginator->links() }}
                </div>
            @endif
        </section>
    @endif
@endsection
