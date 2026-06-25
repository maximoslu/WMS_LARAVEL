@extends('layouts.dashboard')

@section('title', 'Stock | MAXIMO WMS')
@section('topbar_title', 'Stock actual')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <span>Stock</span>
        <span>/</span>
        <span>Stock actual</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Stock actual</h2>
            <span class="sr-only">Vista operativa por articulo</span>
            <span class="ops-page-meta">{{ $rows->count() }} referencias en pantalla</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons ops-toolbar-links">
            <a href="{{ route('items.index') }}" class="button-secondary compact-button btn-compact">Articulos</a>
            <a href="{{ route('locations.index') }}" class="button-secondary compact-button btn-compact">Ubicaciones</a>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="stock-summary kpi-strip" aria-label="Resumen de stock">
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Referencias con stock</strong>
            <span>{{ number_format($summary['references_with_stock'], 0, ',', '.') }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Total unidades</strong>
            <span>{{ number_format($summary['total_units'], 0, ',', '.') }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Total palets</strong>
            <span>{{ number_format($summary['total_pallets'], 0, ',', '.') }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Total picos</strong>
            <span>{{ number_format($summary['total_peaks'], 0, ',', '.') }}</span>
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

            <label class="auth-field">
                <span>SKU o descripcion</span>
                <input
                    type="text"
                    name="search"
                    value="{{ $filters['search'] }}"
                    class="auth-input"
                    placeholder="Buscar referencia o descripcion"
                >
            </label>

            <label class="auth-field">
                <span>Lote</span>
                <input
                    type="text"
                    name="lot"
                    value="{{ $filters['lot'] }}"
                    class="auth-input"
                    placeholder="Filtrar por lote"
                >
            </label>

            <label class="auth-field">
                <span>Estado</span>
                <select name="stock_state" class="auth-input">
                    <option value="with_stock" @selected($filters['stock_state'] === 'with_stock')>Con stock</option>
                    <option value="without_stock" @selected($filters['stock_state'] === 'without_stock')>Sin stock</option>
                    <option value="all" @selected($filters['stock_state'] === 'all')>Todos</option>
                </select>
            </label>

            <label class="auth-field">
                <span>Picos</span>
                <select name="peak_state" class="auth-input">
                    <option value="all" @selected($filters['peak_state'] === 'all')>Todos</option>
                    <option value="with_peaks" @selected($filters['peak_state'] === 'with_peaks')>Solo con picos</option>
                </select>
            </label>

            <label class="auth-field">
                <span>Ubicacion</span>
                <input
                    type="text"
                    name="location"
                    value="{{ $filters['location'] }}"
                    class="auth-input"
                    placeholder="Buscar ubicacion"
                >
            </label>

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('stock.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($rows->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin resultados</span>
            <h3>No hay referencias para estos filtros</h3>
            <p>Ajusta los filtros para recuperar referencias con o sin stock.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card stock-desktop-table">
            <div class="data-table-wrap stock-table-wrap">
                <table class="data-table stock-table table-compact" aria-label="Vista operativa de stock">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>SKU</th>
                            <th>Descripcion</th>
                            <th>Cantidad total</th>
                            <th>Lote</th>
                            <th>Uds/palet</th>
                            <th>Palets completos</th>
                            <th>Picos</th>
                            <th>Pico 1</th>
                            <th>Pico 2</th>
                            <th>Pico 3</th>
                            <th>Pico 4</th>
                            <th>Pico 5</th>
                            <th>Mas picos</th>
                            <th>Total palets</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="stock-row">
                                <td>
                                    <div class="stock-cell-main">
                                        <strong>{{ $row['client_name'] }}</strong>
                                        <span>{{ $row['client_code'] }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="stock-cell-main">
                                        <strong>{{ $row['sku'] }}</strong>
                                        @unless ($row['item_active'])
                                            <span class="stock-badge small-badge badge-compact">Inactivo</span>
                                        @endunless
                                    </div>
                                </td>
                                <td>
                                    <div class="stock-cell-main">
                                        <strong>{{ $row['description'] }}</strong>
                                        <span>{{ $row['location_summary'] !== '' ? 'Ubic.: '.$row['location_summary'] : 'Sin ubicacion' }}</span>
                                    </div>
                                </td>
                                <td class="stock-total">{{ number_format($row['total_units'], 0, ',', '.') }}</td>
                                <td>{{ $row['lot_label'] }}</td>
                                <td>{{ number_format($row['units_per_pallet'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['full_pallets'], 0, ',', '.') }}</td>
                                <td>
                                    <span class="stock-badge small-badge badge-compact{{ $row['pico_count'] > 0 ? ' stock-badge-pico' : '' }}">
                                        {{ number_format($row['pico_count'], 0, ',', '.') }}
                                    </span>
                                </td>
                                @foreach ($row['pico_columns'] as $peak)
                                    <td>{{ $peak !== null ? number_format($peak, 0, ',', '.') : '-' }}</td>
                                @endforeach
                                <td>
                                    @if ($row['peak_overflow_count'] > 0)
                                        <span class="stock-badge small-badge badge-compact stock-badge-pico">+{{ $row['peak_overflow_count'] }} picos</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>{{ number_format($row['total_pallets'], 0, ',', '.') }}</td>
                                <td>
                                    @if ($row['has_peaks'])
                                        <details class="pico-details">
                                            <summary>Ver picos</summary>
                                            <ul class="pico-detail-list">
                                                @foreach ($row['peak_details'] as $peak)
                                                    <li>
                                                        <strong>{{ $peak['pallet_code'] ?: 'Sin codigo' }}</strong>
                                                        <span>{{ $peak['location_label'] !== '' ? $peak['location_label'] : 'Sin ubicacion' }}</span>
                                                        <span>{{ number_format($peak['quantity_units'], 0, ',', '.') }} uds</span>
                                                        <span>Diferencia: {{ $peak['difference_units'] > 0 ? '+' : '' }}{{ number_format($peak['difference_units'], 0, ',', '.') }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @else
                                        <span class="text-muted">Sin picos</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="stock-mobile-list" aria-label="Vista movil de stock">
            @foreach ($rows as $row)
                <article class="surface-card stock-mobile-card compact-card">
                    <div class="stock-cell-main">
                        <strong>{{ $row['sku'] }}</strong>
                        <span>{{ $row['client_name'] }} / {{ $row['lot_label'] }}</span>
                    </div>

                    <p>{{ $row['description'] }}</p>

                    <div class="stock-mobile-metrics">
                        <div>
                            <span>Total unidades</span>
                            <strong class="stock-total">{{ number_format($row['total_units'], 0, ',', '.') }}</strong>
                        </div>
                        <div>
                            <span>Uds/palet</span>
                            <strong>{{ number_format($row['units_per_pallet'], 0, ',', '.') }}</strong>
                        </div>
                        <div>
                            <span>Palets completos</span>
                            <strong>{{ number_format($row['full_pallets'], 0, ',', '.') }}</strong>
                        </div>
                        <div>
                            <span>Total palets</span>
                            <strong>{{ number_format($row['total_pallets'], 0, ',', '.') }}</strong>
                        </div>
                    </div>

                    <div class="stock-pill-list">
                        <span class="stock-badge small-badge badge-compact{{ $row['pico_count'] > 0 ? ' stock-badge-pico' : '' }}">
                            Picos: {{ number_format($row['pico_count'], 0, ',', '.') }}
                        </span>
                        @if ($row['peak_overflow_count'] > 0)
                            <span class="stock-badge small-badge badge-compact stock-badge-pico">+{{ $row['peak_overflow_count'] }} picos</span>
                        @endif
                        <span class="stock-badge small-badge badge-compact">
                            {{ $row['location_summary'] !== '' ? $row['location_summary'] : 'Sin ubicacion' }}
                        </span>
                    </div>

                    @if ($row['has_peaks'])
                        <details class="pico-details">
                            <summary>Ver picos</summary>
                            <ul class="pico-detail-list">
                                @foreach ($row['peak_details'] as $peak)
                                    <li>
                                        <strong>{{ $peak['pallet_code'] ?: 'Sin codigo' }}</strong>
                                        <span>{{ $peak['location_label'] !== '' ? $peak['location_label'] : 'Sin ubicacion' }}</span>
                                        <span>{{ number_format($peak['quantity_units'], 0, ',', '.') }} uds</span>
                                        <span>Diferencia: {{ $peak['difference_units'] > 0 ? '+' : '' }}{{ number_format($peak['difference_units'], 0, ',', '.') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </article>
            @endforeach
        </section>
    @endif
@endsection
