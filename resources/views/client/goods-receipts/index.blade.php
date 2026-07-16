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
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card client-delivery-notes-header">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">ALBARANES</h2>
            <span class="ops-page-meta">{{ $totalDocuments }} documentos</span>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (! $hasClient)
        <div class="alert alert-error">Tu usuario no tiene un cliente asignado.</div>
    @endif

    <section class="surface-card item-filter-card compact-card client-delivery-notes-filters">
        <form method="GET" action="{{ route('client-goods-receipts.index') }}" class="stock-filters compact-filters filters-compact">
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

            <label class="auth-field">
                <span>Buscar</span>
                <input
                    type="text"
                    name="search"
                    value="{{ $filters['search'] }}"
                    class="auth-input"
                    placeholder="Albaran, salida, destino"
                >
            </label>

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Buscar</button>
                <a href="{{ route('client-goods-receipts.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    <section class="client-delivery-notes-grid" aria-label="Albaranes de entrada y salida">
        <article class="surface-card compact-card client-delivery-notes-panel">
            <div class="ops-section-heading client-delivery-notes-panel-head">
                <strong>ALBARANES DE ENTRADA</strong>
                <span class="ops-status badge-compact">{{ $totalEntryDocuments }}</span>
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
                            <a href="{{ route('client-goods-receipts.download', $receipt) }}" class="button-secondary compact-button btn-table">Descargar</a>
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

        <article class="surface-card compact-card client-delivery-notes-panel">
            <div class="ops-section-heading client-delivery-notes-panel-head">
                <strong>ALBARANES DE SALIDA</strong>
                <span class="ops-status badge-compact">{{ $totalDispatchDocuments }}</span>
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
                            <a href="{{ route('client-goods-receipts.dispatches.download', $dispatch) }}" class="button-secondary compact-button btn-table">Descargar</a>
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
@endsection
