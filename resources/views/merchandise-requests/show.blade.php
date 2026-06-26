@extends('layouts.dashboard')

@section('title', 'Detalle de solicitud | MAXIMO WMS')
@section('topbar_title', 'Detalle de solicitud')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <span>Operaciones</span>
        <span>/</span>
        <a href="{{ route('merchandise-requests.index') }}">Solicitudes</a>
        <span>/</span>
        <span>{{ $merchandiseRequest->delivery_reference ?: 'Solicitud #'.$merchandiseRequest->id }}</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <div class="goods-receipt-title">
                <h2 class="ops-page-title page-title-compact">{{ $merchandiseRequest->delivery_reference ?: 'Solicitud #'.$merchandiseRequest->id }}</h2>
                <span class="request-status-pill request-status-pill--{{ $merchandiseRequest->status }}">{{ $merchandiseRequest->statusLabel() }}</span>
            </div>
            <span class="ops-page-meta">{{ $merchandiseRequest->client->name }} / {{ optional($merchandiseRequest->requested_date)->format('d/m/Y') ?: 'Sin fecha' }}</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons">
            @if ($merchandiseRequest->isEditableByClient() && (auth()->user()->hasRole(\App\Models\Role::CLIENTE) || auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION)))
                <a href="{{ route('merchandise-requests.edit', $merchandiseRequest) }}" class="button-secondary compact-button btn-compact">Editar</a>

                <form method="POST" action="{{ route('merchandise-requests.cancel', $merchandiseRequest) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="button-secondary compact-button btn-compact">Cancelar</button>
                </form>
            @endif

            @if (auth()->user()->canAccessRole(\App\Models\Role::ALMACEN) && ! auth()->user()->hasRole(\App\Models\Role::CLIENTE) && $merchandiseRequest->status === \App\Models\MerchandiseRequest::STATUS_CREATED)
                <form method="POST" action="{{ route('merchandise-requests.prepare', $merchandiseRequest) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="button-primary compact-button btn-compact">Marcar preparado</button>
                </form>
            @endif

            @if (auth()->user()->canAccessRole(\App\Models\Role::ALMACEN) && ! auth()->user()->hasRole(\App\Models\Role::CLIENTE) && $merchandiseRequest->status === \App\Models\MerchandiseRequest::STATUS_PREPARED)
                <form method="POST" action="{{ route('merchandise-requests.ship', $merchandiseRequest) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="button-primary compact-button btn-compact">Marcar enviada</button>
                </form>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    <section class="goods-receipt-summary">
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Cliente</strong>
            <span>{{ $merchandiseRequest->client->name }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Solicitante</strong>
            <span>{{ $merchandiseRequest->requester?->name ?: 'Usuario no disponible' }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Fecha</strong>
            <span>{{ optional($merchandiseRequest->requested_date)->format('d/m/Y') ?: '-' }}</span>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact">
            <strong>Estado</strong>
            <span>{{ $merchandiseRequest->statusLabel() }}</span>
        </article>
    </section>

    <section class="goods-receipt-grid">
        <article class="surface-card compact-card goods-receipt-card">
            <div class="ops-index-heading">
                <strong>Resumen operativo</strong>
                <span class="ops-page-meta">{{ $merchandiseRequest->lines->count() }} lineas</span>
            </div>

            <dl class="goods-receipt-meta">
                <div>
                    <dt>Referencia</dt>
                    <dd>{{ $merchandiseRequest->delivery_reference ?: '-' }}</dd>
                </div>
                <div>
                    <dt>Preparado por</dt>
                    <dd>{{ $merchandiseRequest->preparer?->name ?: 'Pendiente' }}</dd>
                </div>
                <div>
                    <dt>Preparado el</dt>
                    <dd>{{ optional($merchandiseRequest->prepared_at)->format('d/m/Y H:i') ?: 'Pendiente' }}</dd>
                </div>
                <div>
                    <dt>Enviado el</dt>
                    <dd>{{ optional($merchandiseRequest->shipped_at)->format('d/m/Y H:i') ?: 'Pendiente' }}</dd>
                </div>
            </dl>

            <div class="app-copy">
                <strong>Direccion de entrega</strong>
                <p>{{ $merchandiseRequest->delivery_address ?: 'Sin direccion indicada.' }}</p>
            </div>

            <div class="app-copy">
                <strong>Notas</strong>
                <p>{{ $merchandiseRequest->notes ?: 'Sin notas adicionales.' }}</p>
            </div>
        </article>

        <article class="surface-card compact-card goods-receipt-card">
            <div class="ops-index-heading">
                <strong>Timeline</strong>
                <span class="ops-page-meta">{{ $merchandiseRequest->events->count() }} eventos</span>
            </div>

            <ol class="merchandise-request-timeline">
                @foreach ($merchandiseRequest->events as $event)
                    <li class="merchandise-request-timeline-item">
                        <div class="merchandise-request-timeline-dot"></div>
                        <div class="merchandise-request-timeline-content">
                            <strong>{{ $event->title }}</strong>
                            <span>{{ optional($event->created_at)->format('d/m/Y H:i') }}{{ $event->user ? ' / '.$event->user->name : '' }}</span>
                            @if ($event->description)
                                <p>{{ $event->description }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </article>
    </section>

    <section class="surface-card stock-table-shell compact-card">
        <div class="ops-index-heading">
            <strong>Lineas solicitadas</strong>
            <span class="ops-page-meta">Pedido por palets</span>
        </div>

        <div class="data-table-wrap goods-receipt-lines-wrap">
            <table class="data-table table-compact merchandise-request-lines-table" aria-label="Lineas de solicitud">
                <thead>
                    <tr>
                        <th>Articulo</th>
                        <th>Lote</th>
                        <th>Palets solicitados</th>
                        <th>Uds/palet</th>
                        <th>Total uds</th>
                        <th>Preparado</th>
                        <th>Notas</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($merchandiseRequest->lines as $line)
                        <tr>
                            <td>
                                <div class="stock-cell-main">
                                    <strong>{{ $line->item->sku }}</strong>
                                    <span>{{ $line->item->description }}</span>
                                </div>
                            </td>
                            <td>{{ $line->lot ?: 'Sin lote' }}</td>
                            <td>{{ number_format($line->requested_pallets, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->units_per_pallet, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->requested_units, 0, ',', '.') }}</td>
                            <td>
                                @if ($line->prepared_pallets !== null)
                                    {{ number_format($line->prepared_pallets, 0, ',', '.') }} palets / {{ number_format($line->prepared_units ?? 0, 0, ',', '.') }} uds
                                @else
                                    Pendiente
                                @endif
                            </td>
                            <td>{{ $line->notes ?: '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
