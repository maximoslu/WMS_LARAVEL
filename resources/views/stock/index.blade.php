@extends('layouts.dashboard')

@section('title', 'Stock | MAXIMO WMS')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <span>Stock</span>
        <span>/</span>
        <span>Stock actual</span>
    </nav>

    <section class="surface-card stock-intro-card">
        <div class="app-copy">
            <span class="status-chip">Stock · Visibilidad</span>
            <h2 class="app-page-title">Stock</h2>
            <p class="stock-subtitle">Vista operativa por articulo</p>
            <p>Los picos representan palets con cantidad distinta al estandar del articulo.</p>
        </div>

        <div class="items-hero-actions">
            <a href="{{ route('items.index') }}" class="button-secondary">Abrir articulos</a>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="stock-summary" aria-label="Resumen de stock">
        <article class="surface-card stock-summary-card">
            <strong>Referencias con stock</strong>
            <span>{{ number_format($summary['references_with_stock'], 0, ',', '.') }}</span>
        </article>
        <article class="surface-card stock-summary-card">
            <strong>Total unidades</strong>
            <span>{{ number_format($summary['total_units'], 0, ',', '.') }}</span>
        </article>
        <article class="surface-card stock-summary-card">
            <strong>Total palets</strong>
            <span>{{ number_format($summary['total_pallets'], 0, ',', '.') }}</span>
        </article>
        <article class="surface-card stock-summary-card">
            <strong>Total picos</strong>
            <span>{{ number_format($summary['total_peaks'], 0, ',', '.') }}</span>
        </article>
    </section>

    <section class="surface-card stock-filter-card">
        <form method="GET" action="{{ route('stock.index') }}" class="stock-filters">
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

            <div class="stock-filter-actions">
                <button type="submit" class="button-primary">Filtrar</button>
                <a href="{{ route('stock.index') }}" class="button-secondary">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($rows->isEmpty())
        <article class="surface-card item-empty-state">
            <span class="status-chip">Sin resultados</span>
            <h3>No hay referencias para estos filtros</h3>
            <p>Ajusta cliente, lote, ubicacion o estado para visualizar stock real o referencias sin stock.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell">
            <div class="stock-table-wrap">
                <table class="stock-table" aria-label="Vista operativa de stock">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Referencia/SKU</th>
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
                            <th>Total palets</th>
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
                                            <span class="stock-badge">Inactivo</span>
                                        @endunless
                                    </div>
                                </td>
                                <td>
                                    <div class="stock-cell-main">
                                        <strong>{{ $row['description'] }}</strong>
                                        <span>{{ $row['location_summary'] !== '' ? 'Ubicaciones: '.$row['location_summary'] : 'Sin ubicacion informada' }}</span>
                                    </div>
                                </td>
                                <td class="stock-total">{{ number_format($row['total_units'], 0, ',', '.') }}</td>
                                <td>{{ $row['lot_label'] }}</td>
                                <td>{{ number_format($row['units_per_pallet'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['full_pallets'], 0, ',', '.') }}</td>
                                <td>
                                    <span class="stock-badge{{ $row['pico_count'] > 0 ? ' stock-badge-pico' : '' }}">
                                        {{ number_format($row['pico_count'], 0, ',', '.') }}
                                    </span>
                                </td>
                                @foreach (array_slice($row['pico_columns'], 0, 5) as $peak)
                                    <td>{{ $peak !== null ? number_format($peak, 0, ',', '.') : '—' }}</td>
                                @endforeach
                                <td>{{ number_format($row['total_pallets'], 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="stock-mobile-list" aria-label="Vista movil de stock">
            @foreach ($rows as $row)
                <article class="surface-card stock-mobile-card">
                    <div class="stock-cell-main">
                        <strong>{{ $row['sku'] }}</strong>
                        <span>{{ $row['client_name'] }} · {{ $row['lot_label'] }}</span>
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
                        <span class="stock-badge{{ $row['pico_count'] > 0 ? ' stock-badge-pico' : '' }}">
                            Picos: {{ number_format($row['pico_count'], 0, ',', '.') }}
                        </span>
                        <span class="stock-badge">
                            {{ $row['location_summary'] !== '' ? $row['location_summary'] : 'Sin ubicacion' }}
                        </span>
                    </div>

                    @if ($row['has_peaks'])
                        <p class="stock-note">
                            Detalle picos:
                            {{ collect(array_slice($row['pico_columns'], 0, 5))->filter()->map(fn ($peak) => number_format($peak, 0, ',', '.'))->implode(' · ') }}
                        </p>
                    @endif
                </article>
            @endforeach
        </section>
    @endif
@endsection
