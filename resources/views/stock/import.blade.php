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
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Importar stock desde Excel</h2>
            <span class="ops-page-meta">Solo superadmin. Sustituye el stock del cliente seleccionado al confirmar.</span>
            @if ($preview && $stockImport)
                <p class="stock-intro-helper">Perfil detectado: {{ $preview['profile_label'] }}.</p>
            @endif
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons ops-toolbar-links">
            <a href="{{ route('stock.index') }}" class="button-secondary compact-button btn-compact">Volver a stock</a>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="surface-card stock-filter-card compact-card">
        <form method="POST" action="{{ route('stock.import.preview') }}" enctype="multipart/form-data" class="stock-filters compact-filters filters-compact">
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

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Previsualizar importacion</button>
            </div>
        </form>

        @if ($stockImport?->client?->code === 'EDELVIVES')
            <p class="stock-intro-helper">Se importaran articulos, ubicaciones, pallets y picos del cliente Edelvives.</p>
        @endif
    </section>

    @if ($preview && $stockImport)
        <section class="stock-summary kpi-strip" aria-label="Resumen de importacion">
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Filas leidas</strong>
                <span>{{ number_format($preview['totals']['total_rows'], 0, ',', '.') }}</span>
            </article>
            @if (($preview['profile'] ?? null) === 'edelvives_single_sheet')
                <article class="surface-card stock-summary-card kpi-card kpi-compact">
                    <strong>Ubicaciones usadas</strong>
                    <span>{{ number_format($preview['totals']['locations_detected'] ?? 0, 0, ',', '.') }}</span>
                </article>
            @endif
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Articulos detectados</strong>
                <span>{{ number_format($preview['totals']['catalog_items_detected'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Articulos nuevos</strong>
                <span>{{ number_format($preview['totals']['catalog_items_created'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Articulos actualizados</strong>
                <span>{{ number_format($preview['totals']['catalog_items_updated'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Partidas de stock</strong>
                <span>{{ number_format($preview['rows'] ? count($preview['rows']) : 0, 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Total unidades</strong>
                <span>{{ number_format($preview['totals']['total_units'] ?? 0, 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Pallets completos</strong>
                <span>{{ number_format($preview['totals']['total_full_pallets'] ?? 0, 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Picos totales</strong>
                <span>{{ number_format($preview['totals']['total_peaks_count'] ?? 0, 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Unidades logisticas</strong>
                <span>{{ number_format($preview['totals']['total_logistic_units'] ?? 0, 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Partidas bloqueadas</strong>
                <span>{{ number_format($preview['totals']['blocked_rows'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Articulos sin stock</strong>
                <span>{{ number_format($preview['totals']['catalog_items_without_stock'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Errores bloqueantes en filas</strong>
                <span>{{ number_format($preview['totals']['invalid_rows_ignored'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Filas ignoradas</strong>
                <span>{{ number_format($preview['totals']['skipped_rows'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Errores bloqueantes</strong>
                <span>{{ number_format($preview['totals']['real_errors'], 0, ',', '.') }}</span>
            </article>
        </section>

        <section class="surface-card compact-card">
            <h3>Hojas detectadas</h3>
            <p>Importables: {{ implode(', ', $preview['detected_sheets']['processed']) ?: 'Ninguna' }}</p>
            <p>Ignoradas: {{ implode(', ', $preview['detected_sheets']['ignored']) ?: 'Ninguna' }}</p>
            <p>No soportadas: {{ implode(', ', $preview['detected_sheets']['unsupported']) ?: 'Ninguna' }}</p>
            <p>Se han ignorado referencias internas que empiezan por * o _.</p>
            <p>Las referencias con SKU valido se crearan o actualizaran como articulos aunque no tengan stock.</p>
            @if (($preview['profile'] ?? null) === 'edelvives_single_sheet')
                <p>Se usara el almacen {{ $preview['warehouse_name'] }} y se aseguraran las calles 0-45, A-F, FONDO y SIN UBICACION.</p>
                <p>Gramaje detectado en archivo, no se importara como propiedad independiente.</p>
                <p>Las filas con avisos se importaran. Las filas sin SKU se ignoran.</p>
                <p>Las ubicaciones no reconocidas se importaran en SIN UBICACION.</p>
            @endif
        </section>

        @if ($preview['warnings'] !== [])
            <section class="surface-card compact-card">
                <h3>Avisos</h3>
                <ul>
                    @foreach ($preview['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($preview['fatal_errors'] !== [])
            <section class="surface-card compact-card">
                <h3>Errores fatales</h3>
                <ul>
                    @foreach ($preview['fatal_errors'] as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($preview['row_errors'] !== [])
            <section class="surface-card compact-card">
                <h3>Errores bloqueantes en filas</h3>
                <p>Estas filas no se importaran, pero no bloquean la importacion mientras existan filas validas.</p>
                <ul>
                    @foreach ($preview['row_errors'] as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="surface-card stock-table-shell compact-card">
            <h3>Partidas de stock que se importaran</h3>
            @if ($preview['sample_rows'] === [])
                <p>No hay filas validas para importar.</p>
            @else
                <p>La muestra previa solo incluye partidas de stock con cantidad o desglose positivo.</p>
                <div class="data-table-wrap stock-table-wrap">
                    <table class="data-table stock-table table-compact" aria-label="Muestra del Excel">
                        <thead>
                            <tr>
                                <th>Hoja</th>
                                <th>SKU</th>
                                <th>Descripcion</th>
                                <th>Ubicacion</th>
                                <th>Lote</th>
                                <th>Cantidad</th>
                                <th>Uds/pallet</th>
                                <th>Pallets</th>
                                <th>Picos</th>
                                <th>Bloqueo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview['sample_rows'] as $row)
                                <tr>
                                    <td>{{ $row['source_sheet'] }}</td>
                                    <td>{{ $row['sku'] }}</td>
                                    <td>{{ $row['description'] }}</td>
                                    <td>{{ $row['location_text'] ?: '-' }}</td>
                                    <td>{{ $row['lot'] ?: '-' }}</td>
                                    <td>{{ number_format($row['quantity_units'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($row['units_per_pallet'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($row['full_pallets'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($row['peaks_count'], 0, ',', '.') }}</td>
                                    <td>{{ $row['blocked_reason'] ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="surface-card compact-card">
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

    <section class="surface-card stock-table-shell compact-card">
        <h3>Ultimas importaciones</h3>
        <div class="data-table-wrap stock-table-wrap">
            <table class="data-table stock-table table-compact" aria-label="Historico de importaciones">
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
@endsection





