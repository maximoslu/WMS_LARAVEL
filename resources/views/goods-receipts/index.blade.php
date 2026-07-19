@extends('layouts.dashboard')

@section('title', 'Entradas | MAXIMO WMS')
@section('topbar_title', 'Entradas')

@section('content')
    @php
        $canDeleteReceipts = auth()->user()?->canAccessRole(\App\Models\Role::SUPERADMIN) ?? false;
        $deleteReceiptMessage = 'Vas a borrar esta entrada. Si tiene stock aplicado, se revertirá el stock asociado. Esta acción afecta a trazabilidad. ¿Confirmas?';
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Operaciones'],
            ['label' => 'Entradas'],
        ];
        $visibleReceipts = $receipts->getCollection();
        $visibleLines = $visibleReceipts->sum('lines_count');
        $visibleStockBatches = $visibleReceipts->sum('stock_pallets_count');
        $pendingReceipts = $visibleReceipts
            ->filter(fn ($receipt) => in_array($receipt->status, [
                \App\Models\GoodsReceipt::STATUS_DRAFT,
                \App\Models\GoodsReceipt::STATUS_PENDING_REVIEW,
            ], true))
            ->count();
        $statusFilterLabel = match ($filters['status']) {
            \App\Models\GoodsReceipt::STATUS_DRAFT => 'Borrador',
            \App\Models\GoodsReceipt::STATUS_PENDING_REVIEW => 'Pendiente de revision',
            \App\Models\GoodsReceipt::STATUS_CONFIRMED => 'Confirmada',
            \App\Models\GoodsReceipt::STATUS_CANCELLED => 'Cancelada',
            default => $filters['status'],
        };
        $visibleFilters = collect([
            filled($filters['client_id']) ? 'Cliente seleccionado' : null,
            filled($filters['supplier_id']) ? 'Proveedor seleccionado' : null,
            $filters['status'] !== 'all' ? 'Estado: '.$statusFilterLabel : null,
            filled($filters['search']) ? 'Busqueda: '.$filters['search'] : null,
            filled($filters['date_from']) ? 'Desde: '.$filters['date_from'] : null,
            filled($filters['date_to']) ? 'Hasta: '.$filters['date_to'] : null,
        ])->filter();
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page goods-receipts-list-page">
        <section class="surface-card compact-card wms-list-header goods-receipts-list-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Operaciones / Entradas</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Entradas de mercancía</h2>
                    <span class="wms-list-count">{{ number_format($receipts->total(), 0, ',', '.') }} registros</span>
                </div>
                <p class="wms-list-subtitle">
                    Recepciones de proveedor con documento, lineas, partidas generadas y estado operativo.
                </p>
            </div>

            <div class="wms-list-actions">
                <dl class="wms-list-metrics" aria-label="Resumen visible">
                    <div>
                        <dt>En pagina</dt>
                        <dd>{{ number_format($receipts->count(), 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt>Pendientes</dt>
                        <dd>{{ number_format($pendingReceipts, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt>Partidas</dt>
                        <dd>{{ number_format($visibleStockBatches, 0, ',', '.') }}</dd>
                    </div>
                </dl>

                <div class="wms-row-actions goods-receipts-header-actions">
                    <a href="{{ route('suppliers.index') }}" class="button-secondary compact-button btn-compact">Proveedores</a>
                    <a href="{{ route('goods-receipts.create') }}" class="button-primary compact-button btn-compact wms-list-primary-action">Nueva entrada</a>
                </div>
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <section class="surface-card compact-card wms-filter-panel goods-receipts-filter-panel">
            <form method="GET" action="{{ route('goods-receipts.index') }}" class="wms-filter-grid goods-receipts-filter-form">
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
                        <option value="all" @selected($filters['status'] === 'all')>Todos</option>
                        <option value="{{ \App\Models\GoodsReceipt::STATUS_DRAFT }}" @selected($filters['status'] === \App\Models\GoodsReceipt::STATUS_DRAFT)>Borrador</option>
                        <option value="{{ \App\Models\GoodsReceipt::STATUS_PENDING_REVIEW }}" @selected($filters['status'] === \App\Models\GoodsReceipt::STATUS_PENDING_REVIEW)>Pendiente revision</option>
                        <option value="{{ \App\Models\GoodsReceipt::STATUS_CONFIRMED }}" @selected($filters['status'] === \App\Models\GoodsReceipt::STATUS_CONFIRMED)>Confirmada</option>
                        <option value="{{ \App\Models\GoodsReceipt::STATUS_CANCELLED }}" @selected($filters['status'] === \App\Models\GoodsReceipt::STATUS_CANCELLED)>Cancelada</option>
                    </select>
                </label>

                <label class="auth-field goods-receipts-filter-search">
                    <span>Albaran, documento o proveedor</span>
                    <input
                        type="text"
                        name="search"
                        value="{{ $filters['search'] }}"
                        class="auth-input"
                        placeholder="Buscar entrada"
                    >
                </label>

                <label class="auth-field">
                    <span>Fecha desde</span>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="auth-input">
                </label>

                <label class="auth-field">
                    <span>Fecha hasta</span>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="auth-input">
                </label>

                <div class="wms-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                    <a href="{{ route('goods-receipts.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
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

        @if ($receipts->isEmpty())
            <article class="surface-card compact-card wms-empty-state goods-receipts-empty-state">
                <span class="wms-status-chip wms-status-chip--neutral">Sin resultados</span>
                <div>
                    <h3>No hay entradas con estos filtros</h3>
                    <p>Crea una nueva entrada o ajusta la busqueda para localizar recepciones existentes.</p>
                </div>
                <a href="{{ route('goods-receipts.create') }}" class="button-primary compact-button btn-compact">Nueva entrada</a>
            </article>
        @else
            <section class="surface-card compact-card wms-table-panel goods-receipts-table-panel">
                <div class="wms-table-toolbar">
                    <div>
                        <strong>Listado operativo</strong>
                        <span>{{ number_format($receipts->firstItem() ?? 0, 0, ',', '.') }}-{{ number_format($receipts->lastItem() ?? 0, 0, ',', '.') }} de {{ number_format($receipts->total(), 0, ',', '.') }}</span>
                    </div>
                    <div class="wms-table-totals" aria-label="Totales visibles">
                        <span>{{ number_format($visibleLines, 0, ',', '.') }} lineas</span>
                    </div>
                </div>

                <div class="wms-table-wrap goods-receipts-table-wrap">
                    <table class="wms-data-table goods-receipts-table" aria-label="Listado de entradas">
                        <thead>
                            <tr>
                                <th>Entrada</th>
                                <th>Cliente</th>
                                <th>Proveedor</th>
                                <th>Recepcion</th>
                                <th>Creada por</th>
                                <th class="wms-table-number">Lineas</th>
                                <th class="wms-table-number">Partidas</th>
                                <th>Documento</th>
                                <th>Estado</th>
                                <th class="wms-table-actions-cell">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($receipts as $receipt)
                                <tr>
                                    <td>
                                        <div class="goods-receipts-code-cell">
                                            <strong>{{ $receipt->receipt_number ?: 'Entrada #'.$receipt->id }}</strong>
                                            <span>{{ $receipt->external_document_number ?: 'Sin numero externo' }}</span>
                                        </div>
                                    </td>
                                    <td>{{ $receipt->client->name }}</td>
                                    <td>{{ $receipt->supplier?->name ?: 'Sin proveedor' }}</td>
                                    <td>{{ optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente' }}</td>
                                    <td>{{ $receipt->creator?->name ?: 'Sin usuario' }}</td>
                                    <td class="wms-table-number">{{ number_format($receipt->lines_count, 0, ',', '.') }}</td>
                                    <td class="wms-table-number">{{ number_format($receipt->stock_pallets_count, 0, ',', '.') }}</td>
                                    <td>
                                        @if ($receipt->document_original_name)
                                            <span class="goods-receipts-document-name">{{ $receipt->document_original_name }}</span>
                                        @else
                                            <span class="wms-muted-value">Sin documento</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="wms-status-chip wms-status-chip--{{ $receipt->status }} receipt-status-pill receipt-status-pill--{{ $receipt->status }}">
                                            {{ $receipt->statusLabel() }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="wms-row-actions goods-receipts-row-actions">
                                            <a href="{{ route('goods-receipts.show', $receipt) }}" class="button-secondary compact-button btn-table">Ver</a>

                                            @if (($canDeleteReceipts || ! $receipt->isConfirmed()) && $receipt->status !== \App\Models\GoodsReceipt::STATUS_CANCELLED)
                                                <a href="{{ route('goods-receipts.edit', $receipt) }}" class="button-secondary compact-button btn-table">Editar</a>
                                            @endif

                                            @if ($canDeleteReceipts)
                                                <form
                                                    method="POST"
                                                    action="{{ route('goods-receipts.destroy', $receipt) }}"
                                                    onsubmit="return confirm('{{ $deleteReceiptMessage }}');"
                                                >
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="button-secondary compact-button btn-table goods-receipt-delete-button">Borrar</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="goods-receipts-mobile-list" aria-label="Listado móvil de entradas">
                @foreach ($receipts as $receipt)
                    <article class="surface-card compact-card goods-receipts-mobile-card">
                        <div class="goods-receipts-mobile-card-head">
                            <div class="goods-receipts-code-cell">
                                <strong>{{ $receipt->receipt_number ?: 'Entrada #'.$receipt->id }}</strong>
                                <span>{{ $receipt->external_document_number ?: 'Sin número externo' }}</span>
                            </div>

                            <span class="wms-status-chip wms-status-chip--{{ $receipt->status }} receipt-status-pill receipt-status-pill--{{ $receipt->status }}">{{ $receipt->statusLabel() }}</span>
                        </div>

                        <div class="stock-mobile-metrics">
                            <div>
                                <span>Cliente</span>
                                <strong>{{ $receipt->client->name }}</strong>
                            </div>
                            <div>
                                <span>Proveedor</span>
                                <strong>{{ $receipt->supplier?->name ?: 'Sin proveedor' }}</strong>
                            </div>
                            <div>
                                <span>Recepcion</span>
                                <strong>{{ optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente' }}</strong>
                            </div>
                            <div>
                                <span>Partidas</span>
                                <strong>{{ number_format($receipt->stock_pallets_count, 0, ',', '.') }}</strong>
                            </div>
                        </div>

                        <p class="users-table-email">Creada por: {{ $receipt->creator?->name ?: 'Sin usuario' }}</p>

                        <div class="wms-row-actions goods-receipts-row-actions">
                            <a href="{{ route('goods-receipts.show', $receipt) }}" class="button-secondary compact-button btn-table">Ver</a>
                            @if (($canDeleteReceipts || ! $receipt->isConfirmed()) && $receipt->status !== \App\Models\GoodsReceipt::STATUS_CANCELLED)
                                <a href="{{ route('goods-receipts.edit', $receipt) }}" class="button-secondary compact-button btn-table">Editar</a>
                            @endif
                            @if ($canDeleteReceipts)
                                <form
                                    method="POST"
                                    action="{{ route('goods-receipts.destroy', $receipt) }}"
                                    onsubmit="return confirm('{{ $deleteReceiptMessage }}');"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="button-secondary compact-button btn-table goods-receipt-delete-button">Borrar</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @endforeach
            </section>
        @endif

        @if ($receipts->hasPages())
            <div class="pagination-card surface-card compact-card">
                {{ $receipts->links() }}
            </div>
        @endif
    </div>
@endsection



