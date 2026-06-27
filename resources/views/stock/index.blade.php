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
            <span class="sr-only">Vista operativa por partida y fecha de entrada</span>
            <span class="ops-page-meta">{{ $rows->count() }} filas en pantalla</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons ops-toolbar-links">
            <a href="{{ route('items.index') }}" class="button-secondary compact-button btn-compact">Artículos</a>
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
            <strong>Total pallets</strong>
            <span>{{ number_format($summary['total_pallets'], 0, ',', '.') }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Partidas bloqueadas</strong>
            <span>{{ number_format($summary['blocked_batches'], 0, ',', '.') }}</span>
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
                <span>SKU o descripción</span>
                <input
                    type="text"
                    name="search"
                    value="{{ $filters['search'] }}"
                    class="auth-input"
                    placeholder="Buscar referencia"
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

            <label class="auth-field">
                <span>Ubicación</span>
                <input
                    type="text"
                    name="location"
                    value="{{ $filters['location'] }}"
                    class="auth-input"
                    placeholder="Buscar ubicación"
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
            <h3>No hay partidas para estos filtros</h3>
            <p>Ajusta cliente, lote, estado o ubicación para recuperar inventario o referencias sin stock.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card stock-desktop-table">
            <div class="data-table-wrap stock-table-wrap">
                <table class="data-table stock-table table-compact" aria-label="Vista operativa de stock por partidas">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>SKU</th>
                            <th>Descripción</th>
                            <th>Lote</th>
                            <th>Fecha entrada</th>
                            <th>Estado artículo</th>
                            <th>Estado partida</th>
                            <th>Ubicación</th>
                            <th>Ubicación por defecto</th>
                            <th>Código pallet</th>
                            <th>Unidades</th>
                            <th>Uds/pallet</th>
                            <th>Motivo bloqueo</th>
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
                                <td><strong>{{ $row['sku'] }}</strong></td>
                                <td>{{ $row['description'] }}</td>
                                <td>{{ $row['lot_label'] }}</td>
                                <td>{{ $row['received_at'] ?? '-' }}</td>
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
                                <td>{{ $row['pallet_code'] }}</td>
                                <td class="stock-total">{{ number_format($row['quantity_units'], 0, ',', '.') }}</td>
                                <td>{{ number_format($row['units_per_pallet'], 0, ',', '.') }}</td>
                                <td>{{ $row['blocked_reason'] ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="stock-mobile-list" aria-label="Vista móvil de stock">
            @foreach ($rows as $row)
                <article class="surface-card stock-mobile-card compact-card">
                    <div class="stock-cell-main">
                        <strong>{{ $row['sku'] }}</strong>
                        <span>{{ $row['client_name'] }} / {{ $row['lot_label'] }}</span>
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
                            <span>Ubicación</span>
                            <strong>{{ $row['location_label'] }}</strong>
                        </div>
                        <div>
                            <span>Uds/pallet</span>
                            <strong>{{ number_format($row['units_per_pallet'], 0, ',', '.') }}</strong>
                        </div>
                        <div>
                            <span>Unidades</span>
                            <strong class="stock-total">{{ number_format($row['quantity_units'], 0, ',', '.') }}</strong>
                        </div>
                    </div>

                    @if ($row['default_location_label'] !== 'Sin ubicación por defecto')
                        <p class="users-table-email">Ubicación por defecto: {{ $row['default_location_label'] }}</p>
                    @endif

                    @if ($row['blocked_reason'])
                        <p class="users-table-email">Bloqueo: {{ $row['blocked_reason'] }}</p>
                    @endif
                </article>
            @endforeach
        </section>
    @endif

    <div class="dispatch-inline-help">
        Pendiente definir estrategia FIFO/FEFO para sugerencia automática de salida.
    </div>
@endsection
