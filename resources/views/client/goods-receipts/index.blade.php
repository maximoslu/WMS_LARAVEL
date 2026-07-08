@extends('layouts.dashboard')

@section('title', 'Mis albaranes | MAXIMO WMS')
@section('topbar_title', 'Mis albaranes')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Mis albaranes'],
        ];
        $totalDocuments = $groups->sum(fn (array $group) => $group['receipts']->count());
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Albaranes de entrada</h2>
            <span class="ops-page-meta">{{ $totalDocuments }} documentos</span>
        </div>
        <p class="merchandise-request-summary-copy">Consulta y descarga los albaranes asociados a tus entradas de mercancía.</p>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (! $hasClient)
        <div class="alert alert-error">Tu usuario no tiene un cliente asignado. Contacta con administración para poder consultar tus albaranes.</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
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
                    placeholder="Albarán, documento o proveedor"
                >
            </label>

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('client-goods-receipts.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($totalDocuments === 0)
        <section class="surface-card compact-card">
            <div class="item-empty-state">No hay albaranes disponibles para este periodo.</div>
        </section>
    @else
        @foreach ($groups as $group)
            <section class="surface-card compact-card client-goods-receipts-group">
                <div class="ops-section-heading">
                    <strong>{{ ucfirst($group['label']) }}</strong>
                    <span class="ops-status badge-compact">{{ $group['receipts']->count() }}</span>
                </div>

                <div class="data-table-wrap">
                    <table class="data-table table-compact client-goods-receipts-table" aria-label="Listado de albaranes">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Proveedor</th>
                                <th>Entrada</th>
                                <th>Documento</th>
                                <th>Estado entrada</th>
                                <th>Descargar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['receipts'] as $receipt)
                                <tr>
                                    <td>{{ optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente' }}</td>
                                    <td>{{ $receipt->supplier?->name ?: 'Sin proveedor' }}</td>
                                    <td>{{ $receipt->receipt_number ?: 'Entrada #'.$receipt->id }}</td>
                                    <td>{{ $displayNames[$receipt->id] ?? $receipt->document_original_name }}</td>
                                    <td><span class="receipt-status-pill receipt-status-pill--{{ $receipt->status }}">{{ $receipt->statusLabel() }}</span></td>
                                    <td>
                                        <a href="{{ route('client-goods-receipts.download', $receipt) }}" class="button-secondary compact-button btn-table">Descargar</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="client-goods-receipts-mobile" aria-label="Listado móvil de albaranes">
                    @foreach ($group['receipts'] as $receipt)
                        <article class="surface-card compact-card client-goods-receipt-card">
                            <strong>{{ $displayNames[$receipt->id] ?? $receipt->document_original_name }}</strong>
                            <span class="receipt-status-pill receipt-status-pill--{{ $receipt->status }}">{{ $receipt->statusLabel() }}</span>
                            <p class="users-table-email">Proveedor: {{ $receipt->supplier?->name ?: 'Sin proveedor' }}</p>
                            <p class="users-table-email">Fecha: {{ optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente' }}</p>
                            <a href="{{ route('client-goods-receipts.download', $receipt) }}" class="button-secondary compact-button btn-table">Descargar</a>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
@endsection
