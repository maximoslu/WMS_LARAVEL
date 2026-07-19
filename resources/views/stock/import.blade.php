@extends('layouts.dashboard')

@section('title', 'Importar stock | MAXIMO WMS')
@section('topbar_title', 'Importar stock de cliente')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Stock', 'href' => route('stock.index')],
            ['label' => 'Importar Excel'],
        ];
        $hasPreview = $preview && $stockImport;
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-stock-admin-page wms-stock-import-page">
        <section class="surface-card compact-card wms-list-header wms-stock-admin-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Stock / Importacion controlada</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Importar stock desde Excel</h2>
                    <span class="wms-list-count">Solo superadmin</span>
                </div>
                <p class="wms-list-subtitle">
                    Previsualiza el Excel, revisa avisos y confirma solo cuando el contenido sustituira correctamente el stock del cliente seleccionado.
                </p>
                @if ($hasPreview)
                    <span class="wms-filter-token">Perfil detectado: {{ $preview['profile_label'] }}</span>
                @endif
            </div>

            <div class="wms-stock-admin-header-side">
                <dl class="wms-list-metrics wms-stock-admin-metrics">
                    <div>
                        <dt>Historial</dt>
                        <dd>{{ $recentImports->count() }}</dd>
                    </div>
                    <div>
                        <dt>Preview</dt>
                        <dd>{{ $hasPreview ? 'Si' : 'No' }}</dd>
                    </div>
                    <div>
                        <dt>Cliente</dt>
                        <dd>{{ $stockImport?->client ? 'Si' : 'No' }}</dd>
                    </div>
                </dl>

                <div class="ops-page-actions page-actions-compact action-buttons ops-toolbar-links wms-list-actions">
                    <a href="{{ route('stock.index') }}" class="button-secondary compact-button btn-compact">Volver a stock</a>
                </div>
            </div>
        </section>

        <section class="surface-card compact-card wms-stock-warning">
            <strong>Operacion sensible</strong>
            <p>La confirmacion sustituye el stock actual del cliente seleccionado. Esta fase solo modifica la presentacion de la pantalla, no la importacion ni sus validaciones.</p>
        </section>

        @if ($errors->any())
            <div class="alert alert-error import-alert" role="alert">
                <strong class="import-alert-title">No se ha podido completar la operacion</strong>
                <ul class="import-alert-list">
                    @foreach ($errors->all() as $error)
                        <li><span class="import-error-detail">{{ $error }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="surface-card compact-card wms-filter-panel wms-stock-import-upload">
            <div class="wms-table-toolbar">
                <div>
                    <strong>Paso 1 · Previsualizar archivo</strong>
                    <span>Selecciona cliente y fichero Excel antes de confirmar cualquier cambio.</span>
                </div>
            </div>

            <form method="POST" action="{{ route('stock.import.preview') }}" enctype="multipart/form-data" class="stock-filters compact-filters filters-compact wms-filter-grid wms-stock-filter-grid wms-stock-filter-grid--import">
                @csrf

                <label class="auth-field">
                    <span>Cliente</span>
                    <select name="client_id" class="auth-input" required>
                        <option value="">Selecciona cliente</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected(old('client_id') == $client->id || $stockImport?->client_id === $client->id)>
                                {{ $client->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="auth-field">
                    <span>Fichero Excel</span>
                    <input type="file" name="file" class="auth-input" accept=".xlsx" required>
                </label>

                <div class="stock-filter-actions action-buttons page-actions-compact wms-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Previsualizar importacion</button>
                </div>
            </form>

            @if ($stockImport?->client?->code === 'EDELVIVES')
                <p class="stock-intro-helper">Se importaran articulos, ubicaciones, pallets y picos del cliente Edelvives.</p>
            @endif
        </section>

        @if ($hasPreview)
            <section class="stock-summary kpi-strip wms-import-summary" aria-label="Resumen de importacion">
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Filas leidas</strong><span>{{ number_format($preview['totals']['total_rows'], 0, ',', '.') }}</span></article>
                @if (array_key_exists('valid_rows', $preview['totals']))
                    <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Filas validas</strong><span>{{ number_format($preview['totals']['valid_rows'], 0, ',', '.') }}</span></article>
                @endif
                @if (($preview['profile'] ?? null) === 'edelvives_single_sheet')
                    <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Ubicaciones usadas</strong><span>{{ number_format($preview['totals']['locations_detected'] ?? 0, 0, ',', '.') }}</span></article>
                @endif
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Articulos detectados</strong><span>{{ number_format($preview['totals']['catalog_items_detected'], 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Articulos nuevos</strong><span>{{ number_format($preview['totals']['catalog_items_created'], 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Articulos actualizados</strong><span>{{ number_format($preview['totals']['catalog_items_updated'], 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Partidas de stock</strong><span>{{ number_format($preview['rows'] ? count($preview['rows']) : 0, 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Filas resumen ***</strong><span>{{ number_format($preview['totals']['summary_rows_ignored'] ?? 0, 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Refs internas detectadas</strong><span>{{ number_format($preview['totals']['internal_references_detected'] ?? $preview['totals']['internal_rows'] ?? 0, 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Refs internas con stock</strong><span>{{ number_format($preview['totals']['internal_rows'] ?? 0, 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Total unidades</strong><span>{{ number_format($preview['totals']['total_units'] ?? 0, 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Pallets completos</strong><span>{{ number_format($preview['totals']['total_full_pallets'] ?? 0, 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Picos totales</strong><span>{{ number_format($preview['totals']['total_peaks_count'] ?? 0, 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Pallets almacen</strong><span>{{ number_format($preview['totals']['total_warehouse_pallets'] ?? $preview['totals']['total_logistic_units'] ?? 0, 2, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Pallets internos</strong><span>{{ number_format($preview['totals']['internal_warehouse_pallets'] ?? 0, 2, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Diferencia partidas</strong><span>{{ number_format($preview['totals']['difference_rows'] ?? 0, 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Diferencia pallets</strong><span>{{ number_format($preview['totals']['difference_warehouse_pallets'] ?? 0, 2, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Partidas bloqueadas</strong><span>{{ number_format($preview['totals']['blocked_rows'], 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Articulos sin stock</strong><span>{{ number_format($preview['totals']['catalog_items_without_stock'], 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Errores bloqueantes en filas</strong><span>{{ number_format($preview['totals']['invalid_rows_ignored'], 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Filas ignoradas</strong><span>{{ number_format($preview['totals']['skipped_rows'], 0, ',', '.') }}</span></article>
                <article class="surface-card stock-summary-card kpi-card kpi-compact"><strong>Errores bloqueantes</strong><span>{{ number_format($preview['totals']['real_errors'], 0, ',', '.') }}</span></article>
            </section>

            <section class="surface-card compact-card wms-stock-import-panel">
                <h3>Hojas detectadas</h3>
                <div class="wms-import-detected-grid">
                    <p><strong>Importables:</strong> {{ implode(', ', $preview['detected_sheets']['processed']) ?: 'Ninguna' }}</p>
                    <p><strong>Ignoradas:</strong> {{ implode(', ', $preview['detected_sheets']['ignored']) ?: 'Ninguna' }}</p>
                    <p><strong>No soportadas:</strong> {{ implode(', ', $preview['detected_sheets']['unsupported']) ?: 'Ninguna' }}</p>
                </div>
                <p>Las filas de resumen que empiezan por *** se ignoran. Las referencias que empiezan por _ se importan como VARIOS solo para uso interno.</p>
                <p>Las referencias con SKU valido se crearan o actualizaran como articulos aunque no tengan stock.</p>
                @if (($preview['profile'] ?? null) === 'edelvives_single_sheet')
                    <p>Se usara el almacen {{ $preview['warehouse_name'] }} y se aseguraran las calles 0-45, A-F, FONDO y SIN UBICACION.</p>
                    <p>Gramaje detectado en archivo, no se importara como propiedad independiente.</p>
                    <p>Las filas con avisos se importaran. Las filas sin SKU se ignoran.</p>
                    <p>Las ubicaciones no reconocidas se importaran en SIN UBICACION.</p>
                @endif
            </section>

            @if ($preview['warnings'] !== [])
                <section class="surface-card compact-card import-warnings-card wms-stock-import-message">
                    <h3>Avisos</h3>
                    <ul class="import-message-list">
                        @foreach ($preview['warnings'] as $warning)
                            <li><span class="import-error-detail">{{ $warning }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (! empty($preview['totals']['category_rows']))
                <section class="surface-card compact-card wms-table-panel wms-stock-table-panel">
                    <div class="wms-table-toolbar"><div><strong>Totales por categoria</strong><span>Resumen operativo de filas y pallets almacen.</span></div></div>
                    <div class="data-table-wrap stock-table-wrap wms-table-wrap">
                        <table class="data-table stock-table table-compact wms-stock-data-table" aria-label="Totales por categoria">
                            <thead><tr><th>Categoria</th><th>Filas</th><th>Pallets almacen</th></tr></thead>
                            <tbody>
                                @foreach (\App\Models\StockPallet::stockCategoryOptions() as $category => $label)
                                    <tr>
                                        <td>{{ $label }}</td>
                                        <td>{{ number_format($preview['totals']['category_rows'][$category] ?? 0, 0, ',', '.') }}</td>
                                        <td>{{ number_format($preview['totals']['category_warehouse_pallets'][$category] ?? 0, 2, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if ($preview['fatal_errors'] !== [])
                <section class="surface-card compact-card import-errors-card import-errors-card--fatal wms-stock-import-message">
                    <h3>Errores fatales</h3>
                    <ul class="import-message-list">
                        @foreach ($preview['fatal_errors'] as $error)
                            <li><span class="import-error-detail">{{ $error }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($preview['row_errors'] !== [])
                <section class="surface-card compact-card import-errors-card wms-stock-import-message">
                    <h3>Errores bloqueantes en filas</h3>
                    <p>Estas filas no se importaran, pero no bloquean la importacion mientras existan filas validas.</p>
                    <ul class="import-message-list">
                        @foreach ($preview['row_errors'] as $error)
                            <li><span class="import-error-detail">{{ $error }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="surface-card compact-card wms-table-panel wms-stock-table-panel">
                <div class="wms-table-toolbar">
                    <div>
                        <strong>Partidas de stock que se importaran</strong>
                        <span>La muestra previa solo incluye partidas de stock con cantidad o desglose positivo.</span>
                    </div>
                </div>
                @if ($preview['sample_rows'] === [])
                    <article class="wms-empty-state wms-stock-empty">
                        <span class="wms-status-chip wms-status-chip--neutral">Sin filas validas</span>
                        <div>
                            <h3>No hay filas validas para importar.</h3>
                            <p>Revisa el fichero y vuelve a previsualizar la importacion.</p>
                        </div>
                    </article>
                @else
                    <div class="data-table-wrap stock-table-wrap wms-table-wrap">
                        <table class="data-table stock-table table-compact wms-stock-data-table" aria-label="Muestra del Excel">
                            <thead>
                                <tr>
                                    <th>Hoja</th>
                                    <th>Categoria</th>
                                    <th>SKU</th>
                                    <th>Descripcion</th>
                                    <th>Ubicacion</th>
                                    <th>Lote</th>
                                    <th>Cantidad</th>
                                    <th>Uds/pallet</th>
                                    <th>Pallets almacen</th>
                                    <th>Picos</th>
                                    <th>Bloqueo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['sample_rows'] as $row)
                                    <tr>
                                        <td>{{ $row['source_sheet'] }}</td>
                                        <td>{{ \App\Models\StockPallet::stockCategoryLabelFor($row['stock_category'] ?? null) }}</td>
                                        <td>{{ $row['sku'] }}</td>
                                        <td>{{ $row['description'] }}</td>
                                        <td>{{ $row['location_text'] ?: '-' }}</td>
                                        <td>{{ $row['lot'] ?: '-' }}</td>
                                        <td>{{ number_format($row['quantity_units'], 0, ',', '.') }}</td>
                                        <td>{{ number_format($row['units_per_pallet'], 0, ',', '.') }}</td>
                                        <td>{{ number_format($row['warehouse_pallets'] ?? $row['full_pallets'], 2, ',', '.') }}</td>
                                        <td>{{ number_format($row['peaks_count'], 0, ',', '.') }}</td>
                                        <td>{{ $row['blocked_reason'] ?: '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="surface-card compact-card wms-stock-confirm-panel">
                @if ($preview['can_confirm'])
                    <h3>Confirmacion</h3>
                    <p>Esta accion sustituira el stock actual del cliente {{ $stockImport->client->name }} por el contenido del Excel previsualizado.</p>
                    @if (($preview['profile'] ?? null) === 'edelvives_single_sheet' && $preview['warnings'] !== [])
                        <p>Las filas con avisos se importaran igualmente.</p>
                    @endif
                    @if ($preview['row_errors'] !== [])
                        <p>Las filas invalidas detectadas seran omitidas y no bloquearan la importacion.</p>
                    @endif
                    <form method="POST" action="{{ route('stock.import.confirm') }}" class="stock-filter-actions action-buttons page-actions-compact" onsubmit="return confirm('Seguro que quieres reemplazar el stock de este cliente?');">
                        @csrf
                        <input type="hidden" name="stock_import_id" value="{{ $stockImport->id }}">
                        <button type="submit" class="button-primary compact-button btn-compact">Confirmar importacion</button>
                    </form>
                @else
                    <h3>Confirmacion</h3>
                    <p>No hay filas validas para importar.</p>
                @endif
            </section>
        @endif

        <section class="surface-card compact-card wms-table-panel wms-stock-table-panel">
            <div class="wms-table-toolbar">
                <div>
                    <strong>Ultimas importaciones</strong>
                    <span>Historico reciente de previsualizaciones y cargas confirmadas.</span>
                </div>
            </div>
            <div class="data-table-wrap stock-table-wrap wms-table-wrap">
                <table class="data-table stock-table table-compact wms-stock-data-table" aria-label="Historico de importaciones">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Fichero</th>
                            <th>Estado</th>
                            <th>Filas</th>
                            <th>Importadas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentImports as $import)
                            <tr>
                                <td>{{ $import->created_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ $import->client?->name ?? '-' }}</td>
                                <td>{{ $import->original_filename }}</td>
                                <td>{{ \App\Models\StockImport::statusLabelFor($import->status) }}</td>
                                <td>{{ number_format($import->total_rows, 0, ',', '.') }}</td>
                                <td>{{ number_format($import->imported_rows, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">Todavia no hay importaciones registradas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
