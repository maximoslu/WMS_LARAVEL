@extends('layouts.dashboard')

@section('title', $isClient ? 'STOCK | MAXIMO WMS' : 'Stock | MAXIMO WMS')
@section('topbar_title', $pageTitle)

@section('content')
    @php
        $breadcrumbs = [


        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => $isClient ? 'STOCK' : 'Stock'],
        ['label' => 'Inventario'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    @unless ($isClient)
        <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card wms-page-hero">
            <div class="ops-page-headline">
                <h2 class="ops-page-title page-title-compact">{{ $pageTitle }}</h2>
                <span class="ops-page-meta">{{ $pageSubtitle }}</span>
            </div>

            <div class="ops-page-actions page-actions-compact action-buttons ops-toolbar-links">
                @if (auth()->user()->canAccessRole(\App\Models\Role::ALMACEN))
                    <a href="{{ route('traceability.alerts.index', ['client_id' => $exportClientId]) }}" class="button-secondary compact-button btn-compact wms-action-secondary">Alertas de stock</a>
                @endif
                @if (auth()->user()->canAccessRole(\App\Models\Role::ALMACEN))
                    <a href="{{ route('stock.relocations.create', $exportClientId ? ['client_id' => $exportClientId] : []) }}" class="button-primary compact-button btn-compact wms-action-primary">Reubicar</a>
                @endif
                @if ($canAdjustStock)
                    <a href="{{ route('stock.adjustments.create', $exportClientId ? ['client_id' => $exportClientId] : []) }}" class="button-primary compact-button btn-compact wms-action-primary">Regularizar</a>
                @endif
                <a href="{{ route('items.index') }}" class="button-secondary compact-button btn-compact wms-action-secondary">Articulos</a>
                <a href="{{ route('locations.index') }}" class="button-secondary compact-button btn-compact wms-action-secondary">Ubicaciones</a>
                <a href="{{ route('labels.index') }}" class="button-secondary compact-button btn-compact wms-action-secondary">Etiquetas</a>
                @if (auth()->user()?->isSuperAdmin())
                    <a href="{{ route('stock.import') }}" class="button-primary compact-button btn-compact wms-action-primary">Importar stock</a>
                @endif
            </div>
        </section>
    @endunless

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="stock-summary stock-summary--single" aria-label="Resumen de stock">
        <article class="surface-card stock-summary-card kpi-card kpi-compact{{ $canExportStock ? ' stock-summary-card--with-action' : '' }}">
            @if ($isClient)
                @php
                    $clientPhysicalPallets = (float) ($summary['total_physical_pallets'] ?? $summary['total_warehouse_pallets'] ?? $summary['total_logistic_units'] ?? 0);
                    $clientPhysicalPalletsFormatted = abs($clientPhysicalPallets - round($clientPhysicalPallets)) < 0.00001
                        ? number_format($clientPhysicalPallets, 0, ',', '.')
                        : number_format($clientPhysicalPallets, 2, ',', '.');
                @endphp
                <div class="stock-summary-card-main">
                    <strong>Palés almacenados</strong>
                    <span>{{ $clientPhysicalPalletsFormatted }}</span>
                    <small>Stock físico total</small>
                </div>
            @else
                <div class="stock-summary-card-main">
                    <strong>Pallets almacen</strong>
                    <span>{{ number_format($summary['total_warehouse_pallets'] ?? $summary['total_logistic_units'], 2, ',', '.') }}</span>
                    <small>Total visible</small>
                </div>
            @endif

            @if ($canExportStock)
                <button
                    type="button"
                    class="button-secondary compact-button btn-compact wms-action-secondary"
                    data-stock-export-trigger
                >
                    Descargar
                </button>
            @endif
        </article>
    </section>

    @if ($canExportStock)
        <dialog class="stock-export-modal" data-stock-export-dialog>
            <form method="dialog" class="stock-export-modal-form">
                <h3 class="stock-export-modal-title">Descargar stock</h3>
                <p class="stock-export-modal-copy">Elige formato</p>

                <div class="stock-export-modal-actions">
                    <a href="{{ route('stock.export', ['format' => 'xlsx'] + ($isClient ? [] : ['client_id' => $exportClientId])) }}" class="button-primary compact-button btn-compact">Excel</a>
                    <a href="{{ route('stock.export', ['format' => 'pdf'] + ($isClient ? [] : ['client_id' => $exportClientId])) }}" class="button-primary compact-button btn-compact" target="_blank" rel="noopener noreferrer">PDF</a>
                    <a href="{{ route('stock.export', ['format' => 'csv'] + ($isClient ? [] : ['client_id' => $exportClientId])) }}" class="button-primary compact-button btn-compact">CSV</a>
                    <button type="submit" class="button-secondary compact-button btn-compact">Cancelar</button>
                </div>
            </form>
        </dialog>
    @endif

    <section class="surface-card stock-filter-card compact-card wms-filter-card">
        <form method="GET" action="{{ route('stock.index') }}" class="stock-filters compact-filters filters-compact">
            @if ($canFilterClients)
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
            @endif

            @if ($isClient)
                <label class="auth-field">
                    <span>SKU, descripcion o referencia</span>
                    <input
                        type="text"
                        name="search"
                        value="{{ $filters['search'] }}"
                        class="auth-input"
                        placeholder="Buscar por SKU o descripcion"
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
            @else
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
            @endif

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

            @unless ($isClient)
                <label class="auth-field">
                    <span>Categoria</span>
                    <select name="stock_category" class="auth-input">
                        <option value="all" @selected(($filters['stock_category'] ?? 'all') === 'all')>Todas</option>
                        @foreach (\App\Models\StockPallet::stockCategoryOptions() as $category => $label)
                            <option value="{{ $category }}" @selected(($filters['stock_category'] ?? 'all') === $category)>{{ $label }}</option>
                        @endforeach
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
            @endunless

            @unless ($isClient)
                <label class="auth-field">
                    <span>Picos</span>
                    <select name="only_peaks" class="auth-input">
                        <option value="0" @selected(! $filters['only_peaks'])>Todos</option>
                        <option value="1" @selected($filters['only_peaks'])>Solo con picos</option>
                    </select>
                </label>
            @endunless

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
            <h3>{{ $isClient ? 'No hay inventario con estos filtros' : 'No hay partidas para estos filtros' }}</h3>
            <p>{{ $isClient ? 'Ajusta el buscador, el lote o el estado para localizar tu inventario.' : 'Ajusta cliente, lote, estado o ubicacion para recuperar inventario o referencias sin stock.' }}</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card stock-desktop-table{{ $isClient ? ' stock-table-shell--client' : '' }}">
            <div class="stock-table-header">
                <div class="stock-table-results">Mostrando {{ number_format($paginator->firstItem() ?? 0, 0, ',', '.') }}-{{ number_format($paginator->lastItem() ?? 0, 0, ',', '.') }} de {{ number_format($paginator->total(), 0, ',', '.') }} registros</div>
                @if ($paginator->hasPages())
                    <div class="stock-table-pagination stock-table-pagination--top">
                        {{ $paginator->links() }}
                    </div>
                @endif
            </div>

            <div class="data-table-wrap stock-table-wrap">
                <table class="data-table stock-table table-compact stock-table--master{{ $isClient ? ' stock-table--client' : '' }}" aria-label="Vista operativa de stock por partidas">
                    <thead>
                        <tr>
                            <th class="stock-column-sticky">SKU</th>
                            <th>Descripcion</th>
                            <th>Lote</th>
                            @if ($isClient)
                                <th class="stock-table-number">Palés totales</th>
                            @endif
                            <th class="stock-table-number">Cantidad</th>
                            @unless ($isClient)
                                <th class="stock-table-center">Pallets almacen</th>
                                <th class="stock-table-center">Picos</th>
                            @endunless
                            <th>Estado</th>
                            <th class="stock-table-center">{{ $isClient ? 'Detalle' : 'Accion' }}</th>
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
                                        @unless ($isClient)
                                            <span>{{ $row['client_name'] }}</span>
                                        @endunless
                                    </div>
                                </td>
                                <td>
                                    <div class="stock-description-cell">
                                        <strong>{{ $row['description'] }}</strong>
                                    </div>
                                </td>
                                <td>{{ $row['lot_label'] }}</td>
                                @if ($isClient)
                                    <td class="stock-table-number stock-client-pallet-total">
                                        <strong>{{ number_format($row['total_pallets'], 0, ',', '.') }}</strong>
                                        @if ($row['has_stock'])
                                            <small>
                                                {{ number_format($row['full_pallets'], 0, ',', '.') }} completos
                                                @if ($row['peaks_count'] > 0)
                                                    &middot; {{ number_format($row['peaks_count'], 0, ',', '.') }} {{ $row['peaks_count'] === 1 ? 'pico' : 'picos' }}
                                                @endif
                                            </small>
                                        @endif
                                    </td>
                                @endif
                                <td class="stock-total stock-table-number">{{ number_format($row['quantity_units'], 0, ',', '.') }}</td>
                                @unless ($isClient)
                                    <td class="stock-table-center">{{ number_format($row['warehouse_pallets'], 2, ',', '.') }}</td>
                                    <td class="stock-table-center">
                                        @if ($row['peaks_count'] > 0)
                                            <span class="stock-peak-badge">{{ number_format($row['peaks_count'], 0, ',', '.') }} {{ $row['peaks_count'] === 1 ? 'pico' : 'picos' }}</span>
                                        @else
                                            <span class="stock-empty-badge">Sin picos</span>
                                        @endif
                                    </td>
                                @endunless
                                <td>
                                    @if ($isClient)
                                        <span class="stock-client-state stock-client-state--{{ $row['client_status'] }}">{{ $row['client_status_label'] }}</span>
                                    @else
                                        <div class="stock-status-stack">
                                            <span class="status-badge item-status-badge item-status-badge--{{ $row['item_status'] }}">
                                                {{ $row['item_status_label'] }}
                                            </span>
                                            <span class="status-badge batch-status-badge{{ $row['batch_status'] ? ' batch-status-badge--'.$row['batch_status'] : '' }}">
                                                {{ $row['batch_status_label'] }}
                                            </span>
                                            <span class="status-badge batch-status-badge batch-status-badge--{{ $row['stock_category'] }}">
                                                {{ $row['stock_category_label'] }}
                                            </span>
                                        </div>
                                    @endif
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
                                <td colspan="{{ $isClient ? 7 : 8 }}">
                                    <div class="stock-detail-panel{{ $isClient ? ' stock-detail-panel--client' : '' }}">
                                        <div class="stock-detail-grid">
                                            <article class="stock-detail-card">
                                                <strong>Informacion logistica</strong>
                                                <dl class="stock-detail-list">
                                                    <div><dt>SKU</dt><dd>{{ $row['sku'] }}</dd></div>
                                                    <div><dt>Descripcion</dt><dd>{{ $row['description'] }}</dd></div>
                                                    <div><dt>Lote</dt><dd>{{ $row['lot_label'] }}</dd></div>
                                                    <div><dt>Fecha entrada</dt><dd>{{ $row['received_at'] ?? '-' }}</dd></div>
                                                    <div><dt>Cantidad total</dt><dd>{{ number_format($row['quantity_units'], 0, ',', '.') }}</dd></div>
                                                    <div><dt>Uds/palé</dt><dd>{{ $row['units_per_pallet_label'] }}</dd></div>
                                                    @if ($isClient)
                                                        <div><dt>Palés totales</dt><dd>{{ number_format($row['total_pallets'], 0, ',', '.') }}</dd></div>
                                                        <div><dt>Palés completos</dt><dd>{{ number_format($row['full_pallets'], 0, ',', '.') }}</dd></div>
                                                        <div><dt>Picos</dt><dd>{{ number_format($row['peaks_count'], 0, ',', '.') }}</dd></div>
                                                    @else
                                                        <div><dt>Pallets almacen</dt><dd>{{ number_format($row['warehouse_pallets'], 2, ',', '.') }}</dd></div>
                                                        <div><dt>Picos total</dt><dd>{{ number_format($row['peaks_count'], 0, ',', '.') }}</dd></div>
                                                    @endif
                                                    <div><dt>Ubicacion</dt><dd>{{ $row['location_label'] }}</dd></div>
                                                    <div><dt>Ubicacion por defecto</dt><dd>{{ $row['default_location_label'] }}</dd></div>
                                                </dl>
                                            </article>

                                            <article class="stock-detail-card">
                                                <strong>Estados y contexto</strong>
                                                @if ($isClient)
                                                    <div class="stock-detail-list">
                                                        <div><dt>Estado</dt><dd>{{ $row['client_status_label'] }}</dd></div>
                                                        <div><dt>Categoria</dt><dd>{{ $row['stock_category_label'] }}</dd></div>
                                                    </div>
                                                @else
                                                    <div class="stock-detail-state-stack">
                                                        <span class="status-badge item-status-badge item-status-badge--{{ $row['item_status'] }}">
                                                            {{ $row['item_status_label'] }}
                                                        </span>
                                                        <span class="status-badge batch-status-badge{{ $row['batch_status'] ? ' batch-status-badge--'.$row['batch_status'] : '' }}">
                                                            {{ $row['batch_status_label'] }}
                                                        </span>
                                                        <span class="status-badge batch-status-badge batch-status-badge--{{ $row['stock_category'] }}">
                                                            {{ $row['stock_category_label'] }}
                                                        </span>
                                                    </div>
                                                @endif
                                                @if ($row['blocked_reason'])
                                                    <p class="stock-detail-note">Motivo bloqueo: {{ $row['blocked_reason'] }}</p>
                                                @endif
                                                @foreach ($row['notes'] as $note)
                                                    <p class="stock-detail-note">Nota: {{ $note }}</p>
                                                @endforeach
                                                @if (auth()->user()->canAccessRole(\App\Models\Role::ALMACEN) && $row['row_type'] === 'stock' && $row['item_id'])
                                                    <div class="action-buttons">
                                                        <a href="{{ route('labels.stock-pallet', $row['id']) }}" target="_blank" rel="noopener noreferrer" class="button-secondary compact-button btn-compact">Sacar etiqueta</a>
                                                        <a href="{{ route('stock.batches.edit', $row['id']) }}" class="button-secondary compact-button btn-compact">Editar ubicacion</a>
                                                        @if ($canAdjustStock)
                                                            <a href="{{ route('stock.adjustments.stock-pallet', $row['id']) }}" class="button-secondary compact-button btn-compact">Regularizar</a>
                                                        @endif
                                                    </div>
                                                @endif
                                            </article>
                                        </div>

                                        @if ($isClient && $row['peaks_count'] > 0)
                                            <article class="stock-detail-card stock-detail-card--peaks">
                                                <strong>Detalle de picos</strong>
                                                <div class="stock-client-peak-list">
                                                    @foreach ($row['peak_details'] as $peak)
                                                        <span>{{ $peak['label'] }}: <strong>{{ number_format($peak['units'], 0, ',', '.') }} uds</strong>@if ($peak['location']) &middot; {{ $peak['location'] }}@endif</span>
                                                    @endforeach
                                                </div>
                                            </article>
                                        @elseif (! $isClient)
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
                                        @endif
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
                        <span class="stock-mobile-summary-meta">
                            @if ($isClient)
                                {{ number_format($row['total_pallets'], 0, ',', '.') }} palés
                            @else
                                {{ number_format($row['quantity_units'], 0, ',', '.') }} uds
                            @endif
                        </span>
                    </summary>

                    <p>{{ $row['description'] }}</p>

                    @if ($isClient)
                        <span class="stock-client-state stock-client-state--{{ $row['client_status'] }}">{{ $row['client_status_label'] }}</span>
                    @else
                        <div class="stock-pill-list">
                            <span class="status-badge item-status-badge item-status-badge--{{ $row['item_status'] }}">
                                {{ $row['item_status_label'] }}
                            </span>
                            <span class="status-badge batch-status-badge{{ $row['batch_status'] ? ' batch-status-badge--'.$row['batch_status'] : '' }}">
                                {{ $row['batch_status_label'] }}
                            </span>
                            <span class="status-badge batch-status-badge batch-status-badge--{{ $row['stock_category'] }}">
                                {{ $row['stock_category_label'] }}
                            </span>
                        </div>
                    @endif

                    <div class="stock-mobile-metrics">
                        <div>
                            <span>Entrada</span>
                            <strong>{{ $row['received_at'] ?? '-' }}</strong>
                        </div>
                        <div>
                            <span>Ubicacion</span>
                            <strong>{{ $row['location_label'] }}</strong>
                        </div>
                        @if ($isClient)
                            <div>
                                <span>Cantidad</span>
                                <strong>{{ number_format($row['quantity_units'], 0, ',', '.') }} uds</strong>
                            </div>
                            <div>
                                <span>Desglose</span>
                                <strong>{{ number_format($row['full_pallets'], 0, ',', '.') }} completos &middot; {{ number_format($row['peaks_count'], 0, ',', '.') }} {{ $row['peaks_count'] === 1 ? 'pico' : 'picos' }}</strong>
                            </div>
                            <div>
                                <span>Uds/palé</span>
                                <strong>{{ $row['units_per_pallet_label'] }}</strong>
                            </div>
                        @else
                            <div>
                                <span>Uds/pallet</span>
                                <strong>{{ $row['units_per_pallet_label'] }}</strong>
                            </div>
                            <div>
                                <span>Pallets almacen</span>
                                <strong>{{ number_format($row['warehouse_pallets'], 2, ',', '.') }}</strong>
                            </div>
                            <div>
                                <span>Picos total</span>
                                <strong>{{ number_format($row['peaks_count'], 0, ',', '.') }}</strong>
                            </div>
                        @endif
                    </div>

                    @if ($isClient && $row['peaks_count'] > 0)
                        <div class="stock-client-peak-list">
                            @foreach ($row['peak_details'] as $peak)
                                <span>{{ $peak['label'] }}: <strong>{{ number_format($peak['units'], 0, ',', '.') }} uds</strong></span>
                            @endforeach
                        </div>
                    @elseif (! $isClient)
                        <div class="stock-peak-grid">
                            @foreach ($peakValues as $peak)
                                <div class="stock-peak-card{{ $peak['value'] > 0 ? ' is-active' : '' }}">
                                    <span>{{ $peak['label'] }}</span>
                                    <strong>{{ number_format($peak['value'], 0, ',', '.') }}</strong>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($row['notes'] as $note)
                        <p class="stock-detail-note">Nota: {{ $note }}</p>
                    @endforeach

                    @if ($row['blocked_reason'])
                        <p class="users-table-email">Bloqueo: {{ $row['blocked_reason'] }}</p>
                    @endif

                    @if (auth()->user()->canAccessRole(\App\Models\Role::ALMACEN) && $row['row_type'] === 'stock' && $row['item_id'])
                        <div class="item-form-actions action-buttons">
                            <a href="{{ route('labels.stock-pallet', $row['id']) }}" target="_blank" rel="noopener noreferrer" class="button-secondary compact-button btn-compact">Sacar etiqueta</a>
                            <a href="{{ route('stock.batches.edit', $row['id']) }}" class="button-secondary compact-button btn-compact">Editar ubicacion</a>
                            @if ($canAdjustStock)
                                <a href="{{ route('stock.adjustments.stock-pallet', $row['id']) }}" class="button-secondary compact-button btn-compact">Regularizar</a>
                            @endif
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
