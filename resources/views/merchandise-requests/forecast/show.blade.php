@extends('layouts.dashboard')

@section('title', 'BORRADOR DEL CLIENTE | MAXIMO WMS')
@section('topbar_title', 'BORRADOR DEL CLIENTE')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'PREVISIÓN DE PEDIDOS', 'href' => route('merchandise-requests.forecast.index')],
            ['label' => $merchandiseRequest->referenceCode()],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page merchandise-request-forecast-detail">
        <section class="surface-card compact-card order-header wms-detail-header">
            <div class="wms-detail-header-main"><div class="order-header-main"><span class="order-type-chip">Información provisional</span><h2 class="order-code">{{ $merchandiseRequest->referenceCode() }}</h2><span class="status-badge merchandise-request-status merchandise-request-status--draft">Borrador del cliente</span></div><p>Este pedido todavía está siendo preparado por el cliente. Las cantidades y referencias pueden cambiar hasta su envío definitivo.</p></div>
            <dl class="order-meta wms-detail-meta"><div class="order-meta-item"><dt>Cliente</dt><dd>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</dd></div><div class="order-meta-item"><dt>Usuario creador</dt><dd>{{ $merchandiseRequest->requestedBy?->name ?? 'Sin usuario' }}</dd></div><div class="order-meta-item"><dt>Creado</dt><dd>{{ $merchandiseRequest->created_at?->format('d/m/Y H:i') }}</dd></div><div class="order-meta-item"><dt>Última actualización</dt><dd>{{ $merchandiseRequest->updated_at?->format('d/m/Y H:i') }}</dd></div></dl>
        </section>

        <section class="surface-card compact-card wms-list-metrics" aria-label="Totales provisionales"><div><dt>Líneas</dt><dd>{{ number_format($totals['lines'], 0, ',', '.') }}</dd></div><div><dt>Palés provisionales</dt><dd>{{ number_format($totals['pallets'], 0, ',', '.') }}</dd></div><div><dt>Picos</dt><dd>{{ number_format($totals['peaks'], 0, ',', '.') }}</dd></div><div><dt>Unidades provisionales</dt><dd>{{ number_format($totals['units'], 0, ',', '.') }}</dd></div><div><dt>Para rellenar camión</dt><dd>{{ number_format($totals['fill_truck_lines'], 0, ',', '.') }}</dd></div></section>

        @if (filled($merchandiseRequest->notes))
            <section class="surface-card compact-card wms-flow-card merchandise-request-comments"><div class="wms-section-head"><div><strong>Comentarios del pedido</strong><p>{{ $merchandiseRequest->notes }}</p></div></div></section>
        @endif

        <section class="surface-card compact-card wms-table-panel"><div class="wms-table-toolbar"><div><strong>Líneas del borrador</strong><span>Datos solicitados por el cliente</span></div><div class="wms-table-totals"><span>Sin efectos operativos</span></div></div><div class="wms-table-wrap"><table class="wms-data-table wms-request-table" aria-label="Líneas del borrador del cliente"><thead><tr><th>Referencia</th><th>Descripción</th><th>Tipo</th><th>Lote</th><th>Ubicación / logística</th><th class="wms-table-number">Cantidad</th><th class="wms-table-number">Unidades</th><th>Indicadores</th></tr></thead><tbody>@foreach ($merchandiseRequest->lines as $line)<tr><td><strong>{{ $line->item?->sku ?? 'Sin referencia' }}</strong></td><td>{{ $line->item?->description ?? 'Sin descripción' }}</td><td>{{ $line->lineTypeLabel() }}</td><td>{{ $line->lot ?: 'NO LOTE' }}</td><td>{{ $line->destination_location ?: ($line->stockPallet?->pickingLocationLabel() ?? 'Sin ubicación indicada') }}<small class="wms-table-subline">{{ $line->unitsLabel() }}</small></td><td class="wms-table-number">{{ $line->requestedQuantityLabel() }}</td><td class="wms-table-number">{{ number_format($line->requestedUnitsTotal(), 0, ',', '.') }}</td><td>@if ($line->fill_truck)<span class="wms-line-type-pill wms-line-type-pill--peak">PARA RELLENAR CAMIÓN</span>@endif @if ($line->requiredUnitsLabel())<small class="wms-table-subline">{{ $line->requiredUnitsLabel() }}</small>@endif</td></tr>@endforeach</tbody></table></div></section>

        <div class="wms-list-actions"><a href="{{ route('merchandise-requests.forecast.index') }}" class="button-secondary compact-button btn-compact">Volver a previsión</a></div>
    </div>
@endsection
