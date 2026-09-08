@extends('layouts.dashboard')

@section('title', 'Inventario | MAXIMO WMS')
@section('topbar_title', 'INVENTARIO')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => $isClient ? 'STOCK' : 'Stock', 'href' => route('stock.index')],
            ['label' => 'Inventario'],
        ];
        $selectedClient = $options['client'];
        $exportQuery = collect($filters)
            ->except(['per_page', 'is_client', 'can_see_locations'])
            ->filter(fn (mixed $value): bool => ! in_array($value, [null, '', 'all'], true))
            ->all();
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-inventory-page">
        @if ($errors->any())
            <div class="alert alert-error">{{ $errors->first() }}</div>
        @endif

        <section class="surface-card compact-card wms-list-header wms-inventory-header">
            <div>
                <span class="wms-list-eyebrow">Stock</span>
                <h2>INVENTARIO</h2>
                <p class="wms-list-subtitle">Selecciona el alcance del inventario que quieres realizar.</p>
            </div>

            <div class="wms-list-actions">
                <a href="{{ route('stock.index', $selectedClient ? ['client_id' => $selectedClient->id] : []) }}" class="button-secondary compact-button btn-compact">Volver a stock</a>
                @if ($selectedClient)
                    <a href="{{ route('stock.inventory.export', $exportQuery) }}" class="button-primary compact-button btn-compact">Descargar inventario</a>
                @endif
            </div>
        </section>

        <section class="surface-card compact-card wms-inventory-filter-card">
            <form method="GET" action="{{ route('stock.inventory.index') }}" class="wms-inventory-filter-form">
                <div class="wms-inventory-filter-grid">
                    @if ($isClient)
                        <label class="auth-field">
                            <span>Cliente</span>
                            <input type="text" class="auth-input" value="{{ $selectedClient?->name }}" disabled>
                        </label>
                    @else
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
                    @endif

                    @if ($filters['can_see_locations'])
                        <label class="auth-field">
                            <span>Almacén</span>
                            <select name="warehouse_id" class="auth-input" @disabled(! $selectedClient)>
                                <option value="">Todos los almacenes compatibles</option>
                                @foreach ($options['warehouses'] as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected((string) $filters['warehouse_id'] === (string) $warehouse->id)>
                                        {{ $warehouse->name }}{{ $warehouse->code ? ' · '.$warehouse->code : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    <label class="auth-field">
                        <span>Categoría</span>
                        <select name="stock_category" class="auth-input" @disabled(! $selectedClient)>
                            <option value="all">Todas</option>
                            @foreach ($options['categories'] as $value => $label)
                                <option value="{{ $value }}" @selected($filters['stock_category'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="auth-field">
                        <span>Estado del artículo</span>
                        <select name="item_state" class="auth-input" @disabled(! $selectedClient)>
                            <option value="all" @selected($filters['item_state'] === 'all')>Todos</option>
                            <option value="active" @selected($filters['item_state'] === 'active')>Activos</option>
                            <option value="inactive" @selected($filters['item_state'] === 'inactive')>Inactivos</option>
                        </select>
                    </label>

                    <label class="auth-field">
                        <span>Stock</span>
                        <select name="stock_state" class="auth-input" @disabled(! $selectedClient)>
                            <option value="with_stock" @selected($filters['stock_state'] === 'with_stock')>Solo referencias con existencia</option>
                            <option value="include_zero" @selected($filters['stock_state'] === 'include_zero')>Incluir referencias a cero</option>
                        </select>
                    </label>
                </div>

                <details class="wms-inventory-advanced" @if($filters['batch_status'] !== 'all' || $filters['location_state'] !== 'all' || $filters['location_id'] !== null) open @endif>
                    <summary>Filtros avanzados</summary>
                    <div class="wms-inventory-filter-grid wms-inventory-filter-grid--advanced">
                        <label class="auth-field">
                            <span>Estado de la partida</span>
                            <select name="batch_status" class="auth-input" @disabled(! $selectedClient)>
                                <option value="all">Todos</option>
                                @foreach ($options['batch_statuses'] as $value => $label)
                                    <option value="{{ $value }}" @selected($filters['batch_status'] === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        @if ($filters['can_see_locations'])
                            <label class="auth-field">
                                <span>Asignación de ubicación</span>
                                <select name="location_state" class="auth-input" @disabled(! $selectedClient)>
                                    <option value="all" @selected($filters['location_state'] === 'all')>Todas</option>
                                    <option value="with_location" @selected($filters['location_state'] === 'with_location')>Con ubicación</option>
                                    <option value="without_location" @selected($filters['location_state'] === 'without_location')>Sin ubicación</option>
                                </select>
                            </label>

                            <label class="auth-field">
                                <span>Ubicación concreta</span>
                                <select name="location_id" class="auth-input" @disabled(! $selectedClient || $options['locations']->isEmpty())>
                                    <option value="">Todas las ubicaciones</option>
                                    @foreach ($options['locations'] as $location)
                                        <option value="{{ $location->id }}" @selected((string) $filters['location_id'] === (string) $location->id)>
                                            {{ $location->displayLabel() }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                        @endif

                        <label class="auth-field">
                            <span>Resultados por página</span>
                            <select name="per_page" class="auth-input">
                                @foreach ([25, 50, 100] as $perPage)
                                    <option value="{{ $perPage }}" @selected((int) $filters['per_page'] === $perPage)>{{ $perPage }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </details>

                <div class="wms-inventory-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Ver inventario</button>
                    <a href="{{ route('stock.inventory.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                    @if ($selectedClient)
                        <a href="{{ route('stock.inventory.export', $exportQuery) }}" class="button-secondary compact-button btn-compact">Descargar inventario</a>
                    @endif
                </div>
            </form>
        </section>

        @if (! $selectedClient)
            <section class="surface-card compact-card wms-empty-state wms-inventory-empty">
                Selecciona un cliente para preparar el inventario. Los almacenes, categorías y ubicaciones se acotarán automáticamente.
            </section>
        @else
            <section class="wms-inventory-summary" aria-label="Resumen del inventario filtrado">
                <article class="surface-card compact-card">
                    <span>Referencias</span>
                    <strong>{{ number_format($summary['references'], 0, ',', '.') }}</strong>
                    <small>{{ number_format($summary['lines'], 0, ',', '.') }} {{ $summary['lines'] === 1 ? 'línea' : 'líneas' }} de conteo</small>
                </article>
                <article class="surface-card compact-card">
                    <span>Pallets teóricos</span>
                    <strong>{{ number_format($summary['full_pallets'], 0, ',', '.') }}</strong>
                    <small>Completos</small>
                </article>
                <article class="surface-card compact-card">
                    <span>Picos / unidades</span>
                    <strong>{{ number_format($summary['peaks'], 0, ',', '.') }} / {{ number_format($summary['peak_units'], 0, ',', '.') }}</strong>
                    <small>{{ number_format($summary['total_units'], 0, ',', '.') }} uds totales</small>
                </article>
                <article class="surface-card compact-card">
                    <span>Ubicaciones</span>
                    <strong>{{ number_format($summary['locations'], 0, ',', '.') }}</strong>
                    <small>Afectadas por el filtro</small>
                </article>
            </section>

            @if ($rows->isEmpty())
                <section class="surface-card compact-card wms-empty-state wms-inventory-empty">
                    No hay mercancía para los filtros seleccionados.
                </section>
            @else
                <section class="surface-card compact-card wms-inventory-table-card">
                    <div class="wms-inventory-table-head">
                        <strong>Vista previa</strong>
                        <span>Mostrando {{ number_format($paginator->firstItem() ?? 0, 0, ',', '.') }}-{{ number_format($paginator->lastItem() ?? 0, 0, ',', '.') }} de {{ number_format($paginator->total(), 0, ',', '.') }} líneas</span>
                    </div>
                    <div class="data-table-wrap">
                        <table class="data-table table-compact wms-inventory-table">
                            <thead>
                                <tr>
                                    <th>Almacén</th>
                                    <th>Ubicación</th>
                                    <th>Referencia / SKU</th>
                                    <th>Descripción</th>
                                    <th>Lote</th>
                                    <th class="stock-table-number">Uds/pallet</th>
                                    <th class="stock-table-number">Pallets teóricos</th>
                                    <th class="stock-table-number">Picos / uds</th>
                                    <th class="stock-table-number">Total teórico</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td>{{ $row['warehouse_name'] ?: ($row['warehouse_code'] ?: 'Sin almacén') }}</td>
                                        <td><span class="wms-location-chip">{{ $row['location_label'] }}</span></td>
                                        <td><strong>{{ $row['sku'] }}</strong></td>
                                        <td>{{ $row['description'] }}</td>
                                        <td>{{ $row['lot_label'] }}</td>
                                        <td class="stock-table-number">{{ number_format($row['units_per_pallet'], 0, ',', '.') }}</td>
                                        <td class="stock-table-number">{{ number_format($row['full_pallets'], 0, ',', '.') }}</td>
                                        <td class="stock-table-number">{{ number_format($row['peaks_count'], 0, ',', '.') }} / {{ number_format($row['peak_units'], 0, ',', '.') }}</td>
                                        <td class="stock-table-number"><strong>{{ number_format($row['quantity_units'], 0, ',', '.') }}</strong></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($paginator->hasPages())
                        <div class="wms-inventory-pagination">{{ $paginator->links() }}</div>
                    @endif
                </section>
            @endif
        @endif
    </div>
@endsection
