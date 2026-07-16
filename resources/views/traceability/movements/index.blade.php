@extends('layouts.dashboard')

@section('title', 'Movimientos | Trazabilidad')
@section('topbar_title', 'Movimientos de mercancia')

@section('content')
    <x-breadcrumbs :items="[['label' => 'Trazabilidad', 'href' => route('traceability.index'), 'icon' => 'audit'], ['label' => 'Movimientos']]" />
    @include('traceability._nav')

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('traceability.movements.index') }}" class="stock-filters compact-filters filters-compact traceability-filter-grid">
            <label class="auth-field"><span>Cliente *</span><select name="client_id" class="auth-input"><option value="">Seleccionar</option>@foreach ($clients as $client)<option value="{{ $client->id }}" @selected((int) ($filters['client_id'] ?? 0) === $client->id)>{{ $client->name }}</option>@endforeach</select></label>
            <label class="auth-field"><span>Desde</span><input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="auth-input"></label>
            <label class="auth-field"><span>Hasta</span><input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="auth-input"></label>
            <label class="auth-field"><span>Articulo</span><select name="item_id" class="auth-input"><option value="">Todos</option>@foreach ($items as $item)<option value="{{ $item->id }}" @selected((int) ($filters['item_id'] ?? 0) === $item->id)>{{ $item->sku }} · {{ $item->description }}</option>@endforeach</select></label>
            <label class="auth-field"><span>Lote</span><input type="text" name="lot" value="{{ $filters['lot'] ?? '' }}" class="auth-input" maxlength="100"></label>
            <label class="auth-field"><span>Tipo</span><select name="movement_type" class="auth-input"><option value="">Todos</option>@foreach ($types as $type)<option value="{{ $type }}" @selected(($filters['movement_type'] ?? '') === $type)>{{ str_replace('_', ' ', ucfirst($type)) }}</option>@endforeach</select></label>
            <label class="auth-field"><span>Direccion</span><select name="direction" class="auth-input"><option value="">Todas</option><option value="inbound" @selected(($filters['direction'] ?? '') === 'inbound')>Entrada</option><option value="outbound" @selected(($filters['direction'] ?? '') === 'outbound')>Salida</option></select></label>
            <label class="auth-field"><span>Origen</span><select name="source_type" class="auth-input"><option value="">Todos</option><option value="App\Models\GoodsReceipt" @selected(($filters['source_type'] ?? '') === 'App\Models\GoodsReceipt')>Entrada</option><option value="App\Models\GoodsDispatch" @selected(($filters['source_type'] ?? '') === 'App\Models\GoodsDispatch')>Salida</option><option value="App\Models\StockImport" @selected(($filters['source_type'] ?? '') === 'App\Models\StockImport')>Importacion</option><option value="App\Models\StockPallet" @selected(($filters['source_type'] ?? '') === 'App\Models\StockPallet')>Partida</option></select></label>
            <label class="auth-field"><span>Usuario</span><select name="user_id" class="auth-input"><option value="">Todos</option>@foreach ($users as $user)<option value="{{ $user->id }}" @selected((int) ($filters['user_id'] ?? 0) === $user->id)>{{ $user->name }}</option>@endforeach</select></label>
            <label class="auth-field"><span>Almacen</span><select name="warehouse_id" class="auth-input"><option value="">Todos</option>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((int) ($filters['warehouse_id'] ?? 0) === $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></label>
            <label class="auth-field"><span>Ubicacion</span><select name="location_id" class="auth-input"><option value="">Todas</option>@foreach ($locations as $location)<option value="{{ $location->id }}" @selected((int) ($filters['location_id'] ?? 0) === $location->id)>{{ $location->code }}</option>@endforeach</select></label>
            <label class="auth-field"><span>ID operacion origen</span><input type="number" name="source_id" min="1" value="{{ $filters['source_id'] ?? '' }}" class="auth-input"></label>
            <div class="stock-filter-actions action-buttons page-actions-compact"><button class="button-primary compact-button btn-compact">Filtrar</button><a href="{{ route('traceability.movements.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a></div>
        </form>
    </section>

    @if (empty($filters['client_id']))
        <article class="surface-card item-empty-state compact-card"><span class="status-chip small-badge badge-compact">Consulta controlada</span><h3>Selecciona un cliente</h3><p>El ledger no se consulta globalmente sin un criterio de cliente.</p></article>
    @else
        <section class="traceability-kpi-grid traceability-kpi-grid--small">
            <article class="surface-card kpi-card kpi-compact"><span>Entradas</span><strong>{{ number_format($summary['entries'], 0, ',', '.') }} uds</strong></article>
            <article class="surface-card kpi-card kpi-compact"><span>Salidas</span><strong>{{ number_format($summary['dispatches'], 0, ',', '.') }} uds</strong></article>
            <article class="surface-card kpi-card kpi-compact"><span>Saldo neto</span><strong>{{ number_format($summary['net'], 0, ',', '.') }} uds</strong></article>
            <article class="surface-card kpi-card kpi-compact"><span>Movimientos</span><strong>{{ number_format($summary['count'], 0, ',', '.') }}</strong></article>
        </section>

        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap"><table class="data-table table-compact">
                <thead><tr><th>Fecha</th><th>Cliente / articulo</th><th>Lote</th><th>Tipo</th><th>Origen → destino</th><th class="numeric-cell">Antes</th><th class="numeric-cell">Variacion</th><th class="numeric-cell">Despues</th><th>Usuario</th><th>Correlacion</th></tr></thead>
                <tbody>
                    @forelse ($movements as $movement)
                        <tr>
                            <td><strong>{{ $movement->effective_at?->format('d/m/Y H:i') }}</strong><small class="table-subline">Reg. {{ $movement->recorded_at?->format('d/m H:i') }}</small></td>
                            <td>{{ $movement->client_name }}<small class="table-subline">{{ $movement->sku }} · {{ $movement->description }}</small></td>
                            <td>{{ $movement->lot ?: '—' }}</td>
                            <td><span class="status-chip small-badge badge-compact">{{ str_replace('_', ' ', $movement->movement_type) }}</span><small class="table-subline">@if (class_basename($movement->source_type ?: '') === 'GoodsReceipt')<a href="{{ route('goods-receipts.show', $movement->source_id) }}">Entrada #{{ $movement->source_id }}</a>@elseif (class_basename($movement->source_type ?: '') === 'GoodsDispatch')<a href="{{ route('dispatches.show', $movement->source_id) }}">Salida #{{ $movement->source_id }}</a>@elseif ($movement->source_id){{ class_basename($movement->source_type ?: 'Origen') }} #{{ $movement->source_id }}@else Sin operacion enlazada @endif</small></td>
                            <td>{{ $movement->from_location_id ? '#'.$movement->from_location_id : '—' }} → {{ $movement->to_location_id ? '#'.$movement->to_location_id : '—' }}</td>
                            <td class="numeric-cell">{{ number_format($movement->units_before ?? 0, 0, ',', '.') }}</td>
                            <td class="numeric-cell"><strong>{{ $movement->units_delta > 0 ? '+' : '' }}{{ number_format($movement->units_delta, 0, ',', '.') }}</strong><small class="table-subline">{{ number_format($movement->warehouse_pallets_delta, 2, ',', '.') }} pallets</small></td>
                            <td class="numeric-cell">{{ number_format($movement->units_after ?? 0, 0, ',', '.') }}</td>
                            <td>{{ $movement->user_name ?: 'Sistema' }}</td>
                            <td>@if (auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION))<a href="{{ route('traceability.audit.index', ['correlation_id' => $movement->correlation_id]) }}" class="mono-link">{{ \Illuminate\Support\Str::limit($movement->correlation_id, 8, '') }}</a>@else<span class="mono-link">{{ \Illuminate\Support\Str::limit($movement->correlation_id, 8, '') }}</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="10">No hay movimientos con estos filtros.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </section>
        @if ($movements->hasPages())<div class="pagination-card surface-card compact-card">{{ $movements->links() }}</div>@endif
    @endif
@endsection
