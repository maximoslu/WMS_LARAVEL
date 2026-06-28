@extends('layouts.dashboard')

@section('title', 'Importar stock | MAXIMO WMS')
@section('topbar_title', 'Importar stock de cliente')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <a href="{{ route('stock.index') }}">Stock</a>
        <span>/</span>
        <span>Importar Excel</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Importar stock desde Excel</h2>
            <span class="ops-page-meta">Solo superadmin. Sustituye el stock del cliente seleccionado al confirmar.</span>
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
    </section>

    @if ($preview && $stockImport)
        <section class="stock-summary kpi-strip" aria-label="Resumen de importacion">
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Filas leidas</strong>
                <span>{{ number_format($preview['totals']['total_rows'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Filas disponibles</strong>
                <span>{{ number_format($preview['totals']['available_rows'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Filas bloqueadas</strong>
                <span>{{ number_format($preview['totals']['blocked_rows'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Filas ignoradas</strong>
                <span>{{ number_format($preview['totals']['skipped_rows'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Total unidades</strong>
                <span>{{ number_format($preview['totals']['total_units'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Pallets completos</strong>
                <span>{{ number_format($preview['totals']['total_full_pallets'], 0, ',', '.') }}</span>
            </article>
            <article class="surface-card stock-summary-card kpi-card kpi-compact">
                <strong>Unidades en picos</strong>
                <span>{{ number_format($preview['totals']['total_peak_units'], 0, ',', '.') }}</span>
            </article>
        </section>

        <section class="surface-card compact-card">
            <h3>Hojas detectadas</h3>
            <p>Importables: {{ implode(', ', $preview['detected_sheets']['processed']) ?: 'Ninguna' }}</p>
            <p>Ignoradas: {{ implode(', ', $preview['detected_sheets']['ignored']) ?: 'Ninguna' }}</p>
            <p>No soportadas: {{ implode(', ', $preview['detected_sheets']['unsupported']) ?: 'Ninguna' }}</p>
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

        @if ($preview['errors'] !== [])
            <section class="surface-card compact-card">
                <h3>Errores</h3>
                <ul>
                    @foreach ($preview['errors'] as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="surface-card stock-table-shell compact-card">
            <h3>Muestra previa</h3>
            <p>Confirmar esta importacion reemplazara solo el stock actual del cliente {{ $stockImport->client->name }}.</p>
            <div class="data-table-wrap stock-table-wrap">
                <table class="data-table stock-table table-compact" aria-label="Muestra del Excel">
                    <thead>
                        <tr>
                            <th>Hoja</th>
                            <th>SKU</th>
                            <th>Descripcion</th>
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

            @if ($preview['errors'] === [])
                <form method="POST" action="{{ route('stock.import.confirm') }}" class="stock-filter-actions action-buttons page-actions-compact" style="margin-top: 1rem;">
                    @csrf
                    <input type="hidden" name="stock_import_id" value="{{ $stockImport->id }}">
                    <button type="submit" class="button-primary compact-button btn-compact">Confirmar y sustituir stock del cliente</button>
                </form>
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
                            <td>{{ $import->status }}</td>
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
