@extends('layouts.dashboard')

@section('title', 'Entradas | MAXIMO WMS')
@section('topbar_title', 'Entradas')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <span>Operaciones</span>
        <span>/</span>
        <span>Entradas</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact stock-intro-card compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Entradas de mercancia</h2>
            <span class="ops-page-meta">{{ $receipts->total() }} registros</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons ops-toolbar-links">
            <a href="{{ route('suppliers.index') }}" class="button-secondary compact-button btn-compact">Proveedores</a>
            <a href="{{ route('goods-receipts.create') }}" class="button-primary compact-button btn-compact">Nueva entrada</a>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('goods-receipts.index') }}" class="stock-filters compact-filters filters-compact goods-receipts-filter-form">
            <label class="auth-field">
                <span>Cliente</span>
                <select name="client_id" class="auth-input">
                    <option value="">Todos</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) $filters['client_id'] === (string) $client->id)>
                            {{ $client->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="auth-field">
                <span>Proveedor</span>
                <select name="supplier_id" class="auth-input">
                    <option value="">Todos</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((string) $filters['supplier_id'] === (string) $supplier->id)>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="auth-field">
                <span>Estado</span>
                <select name="status" class="auth-input">
                    <option value="{{ \App\Models\GoodsReceipt::STATUS_DRAFT }}" @selected($filters['status'] === \App\Models\GoodsReceipt::STATUS_DRAFT)>Borrador</option>
                    <option value="{{ \App\Models\GoodsReceipt::STATUS_PENDING_REVIEW }}" @selected($filters['status'] === \App\Models\GoodsReceipt::STATUS_PENDING_REVIEW)>Pendiente revision</option>
                    <option value="{{ \App\Models\GoodsReceipt::STATUS_CONFIRMED }}" @selected($filters['status'] === \App\Models\GoodsReceipt::STATUS_CONFIRMED)>Confirmada</option>
                    <option value="{{ \App\Models\GoodsReceipt::STATUS_CANCELLED }}" @selected($filters['status'] === \App\Models\GoodsReceipt::STATUS_CANCELLED)>Cancelada</option>
                    <option value="all" @selected($filters['status'] === 'all')>Todos</option>
                </select>
            </label>

            <label class="auth-field">
                <span>Buscar</span>
                <input
                    type="text"
                    name="search"
                    value="{{ $filters['search'] }}"
                    class="auth-input"
                    placeholder="Albaran, documento o proveedor"
                >
            </label>

            <div class="stock-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('goods-receipts.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($receipts->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin resultados</span>
            <h3>No hay entradas con estos filtros</h3>
            <p>Crea una nueva entrada o ajusta la busqueda para localizar recepciones existentes.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact goods-receipts-table" aria-label="Listado de entradas">
                    <thead>
                        <tr>
                            <th>Entrada</th>
                            <th>Cliente</th>
                            <th>Proveedor</th>
                            <th>Recepcion</th>
                            <th>Lineas</th>
                            <th>Stock generado</th>
                            <th>Documento</th>
                            <th>IA</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($receipts as $receipt)
                            <tr>
                                <td>
                                    <div class="stock-cell-main">
                                        <strong>{{ $receipt->receipt_number ?: 'Entrada #'.$receipt->id }}</strong>
                                        <span>{{ $receipt->external_document_number ?: 'Sin numero externo' }}</span>
                                    </div>
                                </td>
                                <td>{{ $receipt->client->name }}</td>
                                <td>{{ $receipt->supplier?->name ?: 'Sin proveedor' }}</td>
                                <td>{{ optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente' }}</td>
                                <td>{{ number_format($receipt->lines_count, 0, ',', '.') }}</td>
                                <td>{{ number_format($receipt->stock_pallets_count, 0, ',', '.') }}</td>
                                <td>{{ $receipt->document_original_name ?: '-' }}</td>
                                <td>{{ $receipt->aiStatusLabel() }}</td>
                                <td>
                                    <span class="receipt-status-pill receipt-status-pill--{{ $receipt->status }}">
                                        {{ $receipt->statusLabel() }}
                                    </span>
                                </td>
                                <td>
                                    <div class="inline-actions action-buttons">
                                        <a href="{{ route('goods-receipts.show', $receipt) }}" class="button-secondary compact-button btn-table">Ver</a>

                                        @if (! $receipt->isConfirmed() && $receipt->status !== \App\Models\GoodsReceipt::STATUS_CANCELLED)
                                            <a href="{{ route('goods-receipts.edit', $receipt) }}" class="button-secondary compact-button btn-table">Editar</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($receipts->hasPages())
        <div class="pagination-card surface-card compact-card">
            {{ $receipts->links() }}
        </div>
    @endif
@endsection
