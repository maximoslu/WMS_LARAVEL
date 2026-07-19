@extends('layouts.dashboard')

@section('title', 'Gestion de albaranes | MAXIMO WMS')
@section('topbar_title', 'Gestion de albaranes')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Gestion'],
            ['label' => 'Albaranes'],
        ];
        $visibleDocuments = $documents->getCollection();
        $entryDocuments = $visibleDocuments->where('type', 'entry')->count();
        $dispatchDocuments = $visibleDocuments->where('type', 'dispatch')->count();
        $selectedTypeLabel = $typeOptions[$filters['type']] ?? $filters['type'];
        $selectedDispatchStatusLabel = $filters['dispatch_status'] !== ''
            ? ($dispatchStatuses[$filters['dispatch_status']] ?? $filters['dispatch_status'])
            : null;
        $visibleFilters = collect([
            $hasClientFilter ? 'Cliente seleccionado' : null,
            $filters['type'] !== 'all' ? 'Tipo: '.$selectedTypeLabel : null,
            $filters['supplier_id'] > 0 ? 'Proveedor seleccionado' : null,
            $selectedDispatchStatusLabel !== null ? 'Estado salida: '.$selectedDispatchStatusLabel : null,
            filled($filters['date_from']) ? 'Desde: '.$filters['date_from'] : null,
            filled($filters['date_to']) ? 'Hasta: '.$filters['date_to'] : null,
            filled($filters['search']) ? 'Busqueda: '.$filters['search'] : null,
        ])->filter();
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page delivery-notes-list-page delivery-notes-management-page">
        <section class="surface-card compact-card wms-list-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Gestion / Albaranes</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Albaranes</h2>
                    <span class="wms-list-count">{{ number_format($documents->total(), 0, ',', '.') }} documentos</span>
                </div>
                <p class="wms-list-subtitle">
                    Consulta interna controlada de albaranes de entrada y salida por cliente, fecha y origen documental.
                </p>
            </div>

            <div class="wms-list-actions">
                <dl class="wms-list-metrics" aria-label="Resumen visible">
                    <div>
                        <dt>En pagina</dt>
                        <dd>{{ number_format($documents->count(), 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt>Entradas</dt>
                        <dd>{{ number_format($entryDocuments, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt>Salidas</dt>
                        <dd>{{ number_format($dispatchDocuments, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        @if ($errors->any())
            <div class="alert alert-error">
                @foreach ($errors->all() as $message)
                    <div>{{ $message }}</div>
                @endforeach
            </div>
        @endif

        <section class="surface-card compact-card wms-filter-panel delivery-notes-filter-panel">
            <form method="GET" action="{{ route('delivery-notes.management.index') }}" class="stock-filters compact-filters filters-compact wms-filter-grid delivery-notes-filter-form">
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

                <label class="auth-field">
                    <span>Tipo</span>
                    <select name="type" class="auth-input">
                        @foreach ($typeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                @if ($filters['type'] !== 'dispatch')
                    <label class="auth-field">
                        <span>Proveedor</span>
                        <select name="supplier_id" class="auth-input" @disabled(! $hasClientFilter)>
                            <option value="">Todos</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((string) $filters['supplier_id'] === (string) $supplier->id)>
                                    {{ $supplier->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if ($filters['type'] !== 'entry')
                    <label class="auth-field">
                        <span>Estado salida</span>
                        <select name="dispatch_status" class="auth-input">
                            <option value="">Todos</option>
                            @foreach ($dispatchStatuses as $value => $label)
                                <option value="{{ $value }}" @selected($filters['dispatch_status'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                <label class="auth-field">
                    <span>Fecha desde</span>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="auth-input">
                </label>

                <label class="auth-field">
                    <span>Fecha hasta</span>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="auth-input">
                </label>

                <label class="auth-field delivery-notes-filter-search">
                    <span>Numero, proveedor, pedido o destino</span>
                    <input
                        type="text"
                        name="search"
                        value="{{ $filters['search'] }}"
                        class="auth-input"
                        placeholder="Buscar albaran"
                    >
                </label>

                <div class="wms-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Buscar</button>
                    <a href="{{ route('delivery-notes.management.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                </div>
            </form>

            <div class="wms-filter-summary" aria-label="Filtros aplicados">
                @if ($visibleFilters->isNotEmpty())
                    @foreach ($visibleFilters as $visibleFilter)
                        <span class="wms-filter-token">{{ $visibleFilter }}</span>
                    @endforeach
                @else
                    <span class="wms-filter-muted">Sin filtros aplicados</span>
                @endif
            </div>
        </section>

        @if (! $hasClientFilter)
            <article class="surface-card compact-card wms-empty-state">
                <span class="wms-status-chip wms-status-chip--neutral">Consulta controlada</span>
                <div>
                    <h3>Selecciona un cliente para buscar albaranes</h3>
                    <p>No se cargan documentos de todos los clientes sin criterio.</p>
                </div>
            </article>
        @elseif ($documents->isEmpty())
            <article class="surface-card compact-card wms-empty-state">
                <span class="wms-status-chip wms-status-chip--neutral">Sin resultados</span>
                <div>
                    <h3>No hay albaranes con estos filtros</h3>
                    <p>Ajusta cliente, fechas, tipo o busqueda para localizar el documento.</p>
                </div>
            </article>
        @else
            <section class="surface-card compact-card wms-table-panel delivery-notes-table-panel">
                <div class="wms-table-toolbar">
                    <div>
                        <strong>Listado documental</strong>
                        <span>{{ number_format($documents->firstItem() ?? 0, 0, ',', '.') }}-{{ number_format($documents->lastItem() ?? 0, 0, ',', '.') }} de {{ number_format($documents->total(), 0, ',', '.') }}</span>
                    </div>
                </div>

                <div class="wms-table-wrap delivery-notes-table-wrap">
                    <table class="wms-data-table delivery-notes-table goods-receipts-table" aria-label="Gestion interna de albaranes">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Cliente</th>
                            <th>Fecha</th>
                            <th>Albaran</th>
                            <th>Numero interno</th>
                            <th>Proveedor</th>
                            <th>Estado</th>
                            <th>Pedido / referencia</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            <tr>
                                <td>
                                    <span class="wms-status-chip wms-status-chip--{{ $document['type'] }} receipt-status-pill receipt-status-pill--{{ $document['type'] }}">
                                        {{ $document['type_label'] }}
                                    </span>
                                </td>
                                <td>{{ $document['client'] }}</td>
                                <td>{{ $document['date_label'] }}</td>
                                <td>
                                    <div class="delivery-notes-code-cell">
                                        <strong>{{ $document['display_name'] }}</strong>
                                    </div>
                                </td>
                                <td>{{ $document['number'] }}</td>
                                <td>{{ $document['supplier'] ?: '-' }}</td>
                                <td>{{ $document['status'] ?: '-' }}</td>
                                <td>{{ $document['related'] ?: '-' }}</td>
                                <td>
                                    <div class="wms-row-actions delivery-notes-row-actions">
                                        <a href="{{ $document['download_url'] }}" class="button-secondary compact-button btn-table">Descargar</a>
                                        <a href="{{ $document['detail_url'] }}" class="button-secondary compact-button btn-table">Abrir origen</a>
                                        @if ($document['request_url'])
                                            <a href="{{ $document['request_url'] }}" class="button-secondary compact-button btn-table">Pedido</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

            @if ($documents->hasPages())
                <div class="pagination-card surface-card compact-card">
                    {{ $documents->links() }}
                </div>
            @endif
        @endif
    </div>
@endsection
