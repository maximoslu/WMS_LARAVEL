@extends('layouts.dashboard')

@section('title', 'ALBARANES | MAXIMO WMS')
@section('topbar_title', 'ALBARANES')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'ALBARANES'],
        ];
        $totalEntryDocuments = $receiptDocuments->total();
        $totalDispatchDocuments = $dispatchDocuments->total();
        $totalDocuments = $totalEntryDocuments + $totalDispatchDocuments;
        $visibleFilters = collect([
            filled($filters['month']) ? 'Mes: '.$filters['month'] : null,
            filled($filters['supplier_id']) ? 'Proveedor seleccionado' : null,
            filled($filters['search']) ? 'Busqueda: '.$filters['search'] : null,
        ])->filter();
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page delivery-notes-list-page client-delivery-notes-page">
        <section class="surface-card compact-card wms-list-header client-delivery-notes-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Cliente / Documentos</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">ALBARANES</h2>
                    <span class="wms-list-count">{{ number_format($totalDocuments, 0, ',', '.') }} documentos</span>
                </div>
                <p class="wms-list-subtitle">
                    Consulta y descarga de albaranes de entrada y salida asociados a tu cliente.
                </p>
            </div>

            <div class="wms-list-actions">
                <dl class="wms-list-metrics" aria-label="Resumen visible">
                    <div>
                        <dt>Entradas</dt>
                        <dd>{{ number_format($totalEntryDocuments, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt>Salidas</dt>
                        <dd>{{ number_format($totalDispatchDocuments, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt>Total</dt>
                        <dd>{{ number_format($totalDocuments, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if (! $hasClient)
            <div class="alert alert-error">Tu usuario no tiene un cliente asignado.</div>
        @endif

        <section class="surface-card compact-card wms-filter-panel client-delivery-notes-filters">
            <form method="GET" action="{{ route('client-goods-receipts.index') }}" class="stock-filters compact-filters filters-compact wms-filter-grid client-delivery-notes-filter-form">
                <label class="auth-field">
                    <span>Mes</span>
                    <select name="month" class="auth-input">
                        <option value="">Todos</option>
                        @foreach ($availableMonths as $option)
                            <option value="{{ $option['value'] }}" @selected($filters['month'] === $option['value'])>
                                {{ ucfirst($option['label']) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="auth-field">
                    <span>Proveedor</span>
                    <select name="supplier_id" class="auth-input">
                        <option value="">Todos</option>
                        @foreach ($availableSuppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((string) $filters['supplier_id'] === (string) $supplier->id)>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="auth-field client-delivery-notes-filter-search">
                    <span>Albaran, salida o destino</span>
                    <input
                        type="text"
                        name="search"
                        value="{{ $filters['search'] }}"
                        class="auth-input"
                        placeholder="Buscar documento"
                    >
                </label>

                <div class="wms-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Buscar</button>
                    <a href="{{ route('client-goods-receipts.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
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

        <section class="client-delivery-notes-grid" aria-label="Albaranes de entrada y salida">
            <article class="surface-card compact-card wms-table-panel client-delivery-notes-panel">
                <div class="wms-table-toolbar client-delivery-notes-panel-head">
                    <div>
                        <strong>ALBARANES DE ENTRADA</strong>
                        <span>{{ number_format($totalEntryDocuments, 0, ',', '.') }} documentos</span>
                    </div>
                    <span class="wms-status-chip wms-status-chip--entry">Entrada</span>
                </div>

                @if ($receiptDocuments->isEmpty())
                    <div class="client-delivery-notes-empty">Sin albaranes.</div>
                @else
                    <div class="client-delivery-notes-list">
                        @foreach ($receiptDocuments as $receipt)
                            <div class="client-delivery-note-row">
                                <div class="client-delivery-note-main">
                                    <strong>{{ $displayNames[$receipt->id] ?? $receipt->document_original_name }}</strong>
                                    <span>{{ optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente' }} · {{ $receipt->supplier?->name ?: 'Sin proveedor' }}</span>
                                </div>
                                <div class="wms-row-actions client-delivery-note-actions">
                                    <a href="{{ route('client-goods-receipts.download', $receipt) }}" class="button-secondary compact-button btn-table">Descargar</a>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($receiptDocuments->hasPages())
                        <div class="pagination-card surface-card compact-card">
                            {{ $receiptDocuments->links() }}
                        </div>
                    @endif
                @endif
            </article>

            <article class="surface-card compact-card wms-table-panel client-delivery-notes-panel">
                <div class="wms-table-toolbar client-delivery-notes-panel-head">
                    <div>
                        <strong>ALBARANES DE SALIDA</strong>
                        <span>{{ number_format($totalDispatchDocuments, 0, ',', '.') }} documentos</span>
                    </div>
                    <span class="wms-status-chip wms-status-chip--dispatch">Salida</span>
                </div>

                @if ($dispatchDocuments->isEmpty())
                    <div class="client-delivery-notes-empty">Sin albaranes.</div>
                @else
                    <div class="client-delivery-notes-list">
                        @foreach ($dispatchDocuments as $dispatch)
                            @php
                                $dispatchDate = $dispatch->completed_at ?? $dispatch->sent_at ?? $dispatch->created_at;
                                $destination = $dispatch->merchandiseRequest?->delivery_reference
                                    ?: $dispatch->merchandiseRequest?->delivery_address
                                    ?: $dispatch->client?->formattedDeliveryAddress();
                            @endphp
                            <div class="client-delivery-note-row">
                                <div class="client-delivery-note-main">
                                    <strong>{{ $dispatchDisplayNames[$dispatch->id] ?? $dispatch->dispatchNumber() }}</strong>
                                    <span>{{ optional($dispatchDate)->format('d/m/Y') ?: 'Pendiente' }} · {{ $dispatch->dispatchNumber() }}</span>
                                    @if (filled($destination))
                                        <span>{{ $destination }}</span>
                                    @endif
                                </div>
                                <div class="wms-row-actions client-delivery-note-actions">
                                    <a href="{{ route('client-goods-receipts.dispatches.download', $dispatch) }}" class="button-secondary compact-button btn-table">Descargar</a>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($dispatchDocuments->hasPages())
                        <div class="pagination-card surface-card compact-card">
                            {{ $dispatchDocuments->links() }}
                        </div>
                    @endif
                @endif
            </article>
        </section>
    </div>
@endsection
