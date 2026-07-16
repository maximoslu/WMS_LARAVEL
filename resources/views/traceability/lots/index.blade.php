@extends('layouts.dashboard')

@section('title', 'Lotes | Trazabilidad')
@section('topbar_title', 'Trazabilidad por lote')

@section('content')
    <x-breadcrumbs :items="[['label' => 'Trazabilidad', 'href' => route('traceability.index'), 'icon' => 'audit'], ['label' => 'Lotes']]" />
    @include('traceability._nav')

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('traceability.lots.index') }}" class="stock-filters compact-filters filters-compact">
            <label class="auth-field"><span>Cliente *</span><select name="client_id" class="auth-input" required><option value="">Seleccionar</option>@foreach ($clients as $client)<option value="{{ $client->id }}" @selected((int) ($filters['client_id'] ?? 0) === $client->id)>{{ $client->name }}</option>@endforeach</select></label>
            <label class="auth-field"><span>Lote *</span><input type="text" name="lot" value="{{ $filters['lot'] ?? '' }}" class="auth-input" maxlength="100" required></label>
            <label class="auth-field"><span>Articulo</span><select name="item_id" class="auth-input"><option value="">Todos los articulos del lote</option>@foreach ($items as $item)<option value="{{ $item->id }}" @selected((int) ($filters['item_id'] ?? 0) === $item->id)>{{ $item->sku }} · {{ $item->description }}</option>@endforeach</select></label>
            <label class="auth-field"><span>Proveedor</span><select name="supplier_id" class="auth-input"><option value="">Todos</option>@foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((int) ($filters['supplier_id'] ?? 0) === $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></label>
            <label class="auth-field"><span>Ubicacion</span><select name="location_id" class="auth-input"><option value="">Todas</option>@foreach ($locations as $location)<option value="{{ $location->id }}" @selected((int) ($filters['location_id'] ?? 0) === $location->id)>{{ $location->warehouse?->name }} / {{ $location->code }}</option>@endforeach</select></label>
            <label class="auth-field"><span>Estado</span><select name="status" class="auth-input"><option value="all" @selected(($filters['status'] ?? 'all') === 'all')>Todos</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Activo</option><option value="historical" @selected(($filters['status'] ?? '') === 'historical')>Historico</option><option value="available" @selected(($filters['status'] ?? '') === 'available')>Disponible</option><option value="blocked" @selected(($filters['status'] ?? '') === 'blocked')>Bloqueado</option><option value="obsolete" @selected(($filters['status'] ?? '') === 'obsolete')>Obsoleto</option></select></label>
            <label class="auth-field"><span>Desde</span><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="auth-input"></label>
            <label class="auth-field"><span>Hasta</span><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="auth-input"></label>
            <div class="stock-filter-actions action-buttons"><button class="button-primary compact-button btn-compact">Reconstruir lote</button><a href="{{ route('traceability.lots.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a></div>
        </form>
    </section>

    @if ($trace)
        @php($integrityLabel = ['complete' => 'Completa', 'partial' => 'Parcial', 'inconsistent' => 'Inconsistente'][$trace['integrity']])
        <section class="surface-card ops-page-header page-header-compact compact-card traceability-lot-header">
            <div class="app-copy"><span class="status-chip small-badge badge-compact trace-status--{{ $trace['integrity'] }}">{{ $integrityLabel }}</span><h1 class="ops-page-title page-title-compact">Lote {{ $trace['lot'] }}</h1><p>{{ number_format($trace['current_units'], 0, ',', '.') }} unidades · {{ number_format($trace['current_pallets'], 2, ',', '.') }} pallets actuales</p></div>
        </section>

        @if ($trace['issues'] !== [])
            <section class="surface-card compact-card traceability-integrity-panel"><h2>Integridad del historico</h2>@foreach ($trace['issues'] as $issue)<p class="trace-issue trace-issue--{{ $issue['level'] }}">{{ $issue['message'] }}</p>@endforeach</section>
        @endif

        <div class="traceability-content-grid">
            <section class="surface-card compact-card traceability-detail-card"><h2>Un paso atras</h2>
                @forelse ($trace['receipt_lines'] as $line)
                    <article><strong>{{ $line->goodsReceipt?->receipt_number }}</strong><span>{{ $line->goodsReceipt?->supplier?->name ?: 'Proveedor no identificado' }}</span><span>{{ $line->goodsReceipt?->confirmed_at?->format('d/m/Y') ?: 'Fecha no identificada' }} · {{ number_format($line->quantity_units, 0, ',', '.') }} uds</span>@if ($line->goodsReceipt)<a href="{{ route('goods-receipts.show', $line->goodsReceipt) }}">Abrir entrada</a>@endif</article>
                @empty
                    <p class="helper-text">No existe una entrada historica verificable para este lote.</p>
                @endforelse
            </section>
            <section class="surface-card compact-card traceability-detail-card"><h2>Un paso adelante</h2>
                @forelse ($trace['dispatch_lines'] as $line)
                    <article><strong>{{ $line->dispatch?->dispatchNumber() }}</strong><span>{{ $line->dispatch?->merchandiseRequest?->delivery_reference ?: 'Sin referencia de entrega' }}</span><span>{{ $line->dispatch?->sent_at?->format('d/m/Y') ?: 'Fecha no identificada' }} · {{ number_format($line->loadedUnits(), 0, ',', '.') }} uds</span>@if ($line->dispatch)<a href="{{ route('dispatches.show', $line->dispatch) }}">Abrir salida</a>@endif</article>
                @empty
                    <p class="helper-text">No hay salidas verificables vinculadas a este lote.</p>
                @endforelse
            </section>
        </div>

        <section class="surface-card stock-table-shell compact-card">
            <div class="item-form-header"><div class="app-copy"><h2 class="ops-page-title page-title-compact">Partidas y ubicaciones</h2></div></div>
            <div class="data-table-wrap"><table class="data-table table-compact"><thead><tr><th>SKU</th><th>Estado</th><th>Almacen / ubicacion</th><th class="numeric-cell">Unidades</th><th class="numeric-cell">Pallets</th></tr></thead><tbody>
                @forelse ($trace['stock'] as $batch)<tr><td><strong>{{ $batch->item?->sku }}</strong><small class="table-subline">{{ $batch->item?->description }}</small></td><td>{{ $batch->active ? 'Activa' : 'Historica' }} · {{ $batch->status }}</td><td>{{ $batch->location?->warehouse?->name ?: 'Sin almacen' }}<small class="table-subline">{{ $batch->location?->code ?: 'Sin ubicacion' }}</small></td><td class="numeric-cell">{{ number_format($batch->quantity_units, 0, ',', '.') }}</td><td class="numeric-cell">{{ number_format($batch->warehouse_pallets, 2, ',', '.') }}</td></tr>@empty<tr><td colspan="5">No hay partidas para este lote.</td></tr>@endforelse
            </tbody></table></div>
        </section>

        <section class="surface-card compact-card traceability-timeline"><h2>Cronologia</h2>
            @forelse ($trace['timeline'] as $entry)<article><time>{{ $entry['at']?->format('d/m/Y H:i') ?: 'Fecha desconocida' }}</time><div><strong>{{ $entry['label'] }}</strong><span>{{ $entry['units'] > 0 ? '+' : '' }}{{ number_format($entry['units'], 0, ',', '.') }} uds</span></div></article>@empty<p class="helper-text">Sin hitos historicos.</p>@endforelse
        </section>
    @elseif (filled($filters['client_id'] ?? null) && filled($filters['lot'] ?? null))
        <article class="surface-card item-empty-state compact-card"><h3>No se ha localizado el lote</h3><p>No hay relaciones verificables para cliente y lote con estos criterios.</p></article>
    @endif
@endsection
