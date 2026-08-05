@extends('layouts.dashboard')

@section('title', 'PREVISIÓN DE PEDIDOS | MAXIMO WMS')
@section('topbar_title', 'PREVISIÓN DE PEDIDOS')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Operaciones'],
            ['label' => 'PREVISIÓN DE PEDIDOS'],
        ];
        $visibleFilters = collect([
            filled($filters['client_id']) ? 'Cliente seleccionado' : null,
            filled($filters['creator_id']) ? 'Creador seleccionado' : null,
            filled($filters['date_from']) ? 'Desde: '.$filters['date_from'] : null,
            filled($filters['date_to']) ? 'Hasta: '.$filters['date_to'] : null,
            $filters['has_notes'] ? 'Con comentarios' : null,
            $filters['has_fill_truck'] ? 'Para rellenar camión' : null,
        ])->filter();
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page merchandise-request-forecast">
        <section class="surface-card compact-card wms-list-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Operaciones / Planificación</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">PREVISIÓN DE PEDIDOS</h2>
                    <span class="wms-list-count">{{ number_format($summary['drafts'], 0, ',', '.') }} borradores</span>
                </div>
                <p class="wms-list-subtitle">Pedidos que los clientes están preparando y todavía no han enviado. Información exclusivamente orientativa.</p>
                <small class="wms-table-subline">No se muestran viajes orientativos porque el WMS no tiene una capacidad oficial aprobada por camión.</small>
            </div>
            <dl class="wms-list-metrics" aria-label="Resumen provisional de borradores">
                <div><dt>Borradores activos</dt><dd>{{ number_format($summary['drafts'], 0, ',', '.') }}</dd></div>
                <div><dt>Clientes</dt><dd>{{ number_format($summary['clients'], 0, ',', '.') }}</dd></div>
                <div><dt>Líneas provisionales</dt><dd>{{ number_format($summary['lines'], 0, ',', '.') }}</dd></div>
                <div><dt>Palés provisionales</dt><dd>{{ number_format($summary['pallets'], 0, ',', '.') }}</dd></div>
                <div><dt>Rellenar camión</dt><dd>{{ number_format($summary['fill_truck_lines'], 0, ',', '.') }}</dd></div>
            </dl>
        </section>

        <section class="surface-card compact-card wms-filter-panel">
            <form method="GET" action="{{ route('merchandise-requests.forecast.index') }}" class="wms-filter-grid">
                <label class="auth-field"><span>Cliente</span><select name="client_id" class="auth-input"><option value="">Todos</option>@foreach ($clients as $client)<option value="{{ $client->id }}" @selected($filters['client_id'] === $client->id)>{{ $client->name }}</option>@endforeach</select></label>
                <label class="auth-field"><span>Creador</span><select name="creator_id" class="auth-input"><option value="">Todos</option>@foreach ($creators as $creator)<option value="{{ $creator->id }}" @selected($filters['creator_id'] === $creator->id)>{{ $creator->name }}</option>@endforeach</select></label>
                <label class="auth-field"><span>Desde</span><input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="auth-input"></label>
                <label class="auth-field"><span>Hasta</span><input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="auth-input"></label>
                <label class="auth-field"><span>Ordenar</span><select name="sort" class="auth-input"><option value="updated" @selected($filters['sort'] === 'updated')>Última actualización</option><option value="client" @selected($filters['sort'] === 'client')>Cliente</option><option value="volume" @selected($filters['sort'] === 'volume')>Volumen</option><option value="created" @selected($filters['sort'] === 'created')>Fecha de creación</option></select></label>
                <label class="auth-field"><span>Indicadores</span><span class="wms-checkbox-stack"><label><input type="checkbox" name="has_notes" value="1" @checked($filters['has_notes'])> Con comentarios</label><label><input type="checkbox" name="has_fill_truck" value="1" @checked($filters['has_fill_truck'])> Para rellenar camión</label></span></label>
                <div class="wms-filter-actions"><button type="submit" class="button-primary compact-button btn-compact">Filtrar</button><a href="{{ route('merchandise-requests.forecast.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a></div>
            </form>
            <div class="wms-filter-summary" aria-label="Filtros aplicados">@if ($visibleFilters->isNotEmpty()) @foreach ($visibleFilters as $visibleFilter)<span class="wms-filter-token">{{ $visibleFilter }}</span>@endforeach @else <span class="wms-filter-muted">Sin filtros aplicados</span>@endif</div>
        </section>

        @if ($requests->isEmpty())
            <article class="surface-card compact-card wms-empty-state"><span class="wms-status-chip wms-status-chip--neutral">Sin borradores</span><div><h3>No hay borradores de cliente con estos filtros.</h3><p>Los pedidos enviados pasan al circuito operativo y dejan de formar parte de esta previsión.</p></div></article>
        @else
            <section class="surface-card compact-card wms-table-panel">
                <div class="wms-table-toolbar"><div><strong>Borradores del cliente</strong><span>{{ number_format($requests->firstItem() ?? 0, 0, ',', '.') }}-{{ number_format($requests->lastItem() ?? 0, 0, ',', '.') }} de {{ number_format($requests->total(), 0, ',', '.') }}</span></div><div class="wms-table-totals"><span>{{ number_format($summary['units'], 0, ',', '.') }} uds provisionales</span><span>{{ $summary['latest_updated']?->format('d/m/Y H:i') ?? 'Sin actualizaciones' }}</span></div></div>
                <div class="wms-table-wrap">
                    <table class="wms-data-table wms-request-table" aria-label="Previsión de pedidos">
                        <thead><tr><th>Cliente</th><th>Borrador</th><th>Creador</th><th>Creado</th><th>Actualizado</th><th class="wms-table-number">Líneas</th><th class="wms-table-number">Palés / picos</th><th class="wms-table-number">Unidades</th><th>Indicadores</th><th>Estado</th><th class="wms-table-actions-cell">Acciones</th></tr></thead>
                        <tbody>
                            @foreach ($requests as $merchandiseRequest)
                                @php($totals = $merchandiseRequest->forecast_totals)
                                <tr>
                                    <td>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</td>
                                    <td><strong>{{ $merchandiseRequest->referenceCode() }}</strong><small class="wms-table-subline">Borrador del cliente</small></td>
                                    <td>{{ $merchandiseRequest->requestedBy?->name ?? 'Sin usuario' }}</td>
                                    <td>{{ $merchandiseRequest->created_at?->format('d/m/Y H:i') }}</td>
                                    <td>{{ $merchandiseRequest->updated_at?->format('d/m/Y H:i') }}</td>
                                    <td class="wms-table-number">{{ number_format($totals['lines'], 0, ',', '.') }}</td>
                                    <td class="wms-table-number">{{ number_format($totals['pallets'], 0, ',', '.') }} / {{ number_format($totals['peaks'], 0, ',', '.') }}</td>
                                    <td class="wms-table-number">{{ number_format($totals['units'], 0, ',', '.') }}</td>
                                    <td>@if ($totals['fill_truck_lines'] > 0)<span class="wms-line-type-pill wms-line-type-pill--peak">PARA RELLENAR CAMIÓN ({{ $totals['fill_truck_lines'] }})</span>@endif @if (filled($merchandiseRequest->notes))<span class="wms-table-subline">Con comentarios</span>@endif</td>
                                    <td><span class="status-badge merchandise-request-status merchandise-request-status--draft">Borrador del cliente</span></td>
                                    <td><a href="{{ route('merchandise-requests.forecast.show', $merchandiseRequest) }}" class="button-secondary compact-button btn-compact">Ver detalle</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="wms-pagination">{{ $requests->links() }}</div>
            </section>
        @endif
    </div>
@endsection
