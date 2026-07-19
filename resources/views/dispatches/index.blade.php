@extends('layouts.dashboard')

@section('title', 'Salidas | MAXIMO WMS')
@section('topbar_title', 'Salida de mercancía')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Operaciones'],
            ['label' => 'Salidas'],
        ];
        $visibleDispatches = $dispatches->getCollection();
        $requestDispatches = $visibleDispatches->filter(fn ($dispatch) => $dispatch->merchandiseRequest !== null)->count();
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page dispatch-list-page">
        <section class="surface-card compact-card wms-list-header dispatch-page-header">
            <div class="wms-list-heading dispatch-page-headline">
                <span class="wms-list-kicker">Operaciones / Salidas</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Salida de mercancía</h2>
                    <span class="wms-list-count">{{ number_format($dispatches->total(), 0, ',', '.') }} salidas</span>
                </div>
                <p class="wms-list-subtitle">
                    Expediciones manuales y salidas generadas desde pedidos, con estados y acciones de consulta alineadas.
                </p>
            </div>

            <div class="wms-list-actions">
                <dl class="wms-list-metrics" aria-label="Resumen visible">
                    <div>
                        <dt>En pagina</dt>
                        <dd>{{ number_format($dispatches->count(), 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt>Pendientes</dt>
                        <dd>{{ number_format($pendingRequests->count(), 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt>Desde pedido</dt>
                        <dd>{{ number_format($requestDispatches, 0, ',', '.') }}</dd>
                    </div>
                </dl>

                <a href="{{ route('dispatches.create') }}" class="button-primary compact-button btn-compact wms-list-primary-action">
                    Crear salida manual
                </a>
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <section class="surface-card compact-card wms-filter-panel dispatch-entry-panel">
            <div class="dispatch-entry-grid dispatch-entry-grid--refined">
                <article class="dispatch-entry-card dispatch-entry-card--request">
                    <div class="dispatch-entry-card-head">
                        <span class="wms-status-chip wms-status-chip--pending">Desde pedido</span>
                        <strong>Pedidos pendientes</strong>
                    </div>
                    <p>Revisa solicitudes preparables y genera la salida asociada sin duplicados.</p>
                    <div class="dispatch-entry-card-actions">
                        <a href="{{ route('dispatches.requests.index') }}" class="button-secondary compact-button btn-compact dispatch-entry-action">
                            Ver pedidos pendientes
                        </a>
                    </div>
                </article>

                <article class="dispatch-entry-card dispatch-entry-card--manual">
                    <div class="dispatch-entry-card-head">
                        <span class="wms-status-chip wms-status-chip--neutral">Manual</span>
                        <strong>Salida manual</strong>
                    </div>
                    <p>Registra una expedicion directa seleccionando cliente y mercancia.</p>
                    <div class="dispatch-entry-card-actions">
                        <a href="{{ route('dispatches.create') }}" class="button-primary compact-button btn-compact dispatch-entry-action">
                            Crear salida manual
                        </a>
                    </div>
                </article>
            </div>
        </section>

        <section class="surface-card compact-card wms-table-panel dispatch-section-card">
            <div class="wms-table-toolbar dispatch-section-heading">
                <div class="dispatch-section-intro">
                    <strong>Pedidos pendientes destacados</strong>
                    <span>Solicitudes listas para revisar o convertir en salida.</span>
                </div>
                <a href="{{ route('dispatches.requests.index') }}" class="button-secondary compact-button btn-table dispatch-section-action">Ver todos</a>
            </div>

            @if ($pendingRequests->isEmpty())
                <article class="wms-empty-state dispatch-empty-state">
                    <span class="wms-status-chip wms-status-chip--neutral">Sin pendientes</span>
                    <div>
                        <h3>No hay solicitudes pendientes ahora mismo.</h3>
                        <p>Cuando entren nuevos pedidos preparables apareceran en este acceso rapido.</p>
                    </div>
                </article>
            @else
                <div class="dispatch-pending-list dispatch-pending-list--refined">
                    @foreach ($pendingRequests as $pendingRequest)
                        <article class="dispatch-pending-card dispatch-pending-card--refined">
                            <div class="dispatch-pending-card-copy">
                                <strong>{{ $pendingRequest->referenceCode() }}</strong>
                                <p>{{ $pendingRequest->client?->name ?? 'Sin cliente' }}</p>
                            </div>
                            <div class="dispatch-pending-meta">
                                <span class="wms-status-chip wms-status-chip--{{ $pendingRequest->status }} merchandise-request-status merchandise-request-status--{{ $pendingRequest->status }}">
                                    {{ $pendingRequest->statusLabel() }}
                                </span>
                                <span class="wms-muted-value">{{ number_format($pendingRequest->requestedPalletsCount(), 0, ',', '.') }} pallets</span>
                            </div>
                            <div class="wms-row-actions dispatch-pending-card-actions">
                                <a href="{{ route('dispatches.requests.show', $pendingRequest) }}" class="button-secondary compact-button btn-table">Ver pedido</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($dispatches->isEmpty())
            <article class="surface-card compact-card wms-empty-state dispatch-table-wrap">
                <span class="wms-status-chip wms-status-chip--neutral">Sin salidas</span>
                <div>
                    <h3>Todavía no hay salidas registradas.</h3>
                    <p>Las salidas manuales o generadas desde pedido apareceran aqui con su estado operativo.</p>
                </div>
                <a href="{{ route('dispatches.create') }}" class="button-primary compact-button btn-compact">
                    Crear salida manual
                </a>
            </article>
        @else
            <section class="surface-card compact-card wms-table-panel dispatch-section-card dispatch-section-card--table">
                <div class="wms-table-toolbar dispatch-section-heading">
                    <div class="dispatch-section-intro">
                        <strong>Salidas recientes</strong>
                        <span>{{ number_format($dispatches->firstItem() ?? 0, 0, ',', '.') }}-{{ number_format($dispatches->lastItem() ?? 0, 0, ',', '.') }} de {{ number_format($dispatches->total(), 0, ',', '.') }}</span>
                    </div>
                    <div class="wms-table-totals" aria-label="Totales visibles">
                        <span>{{ number_format($visibleDispatches->count(), 0, ',', '.') }} en pagina</span>
                    </div>
                </div>

                <div class="wms-table-wrap dispatch-table-wrap">
                    <table class="wms-data-table dispatch-table" aria-label="Listado de salidas">
                        <thead>
                            <tr>
                                <th>Salida</th>
                                <th>Cliente</th>
                                <th>Origen</th>
                                <th>Pedido</th>
                                <th>Estado</th>
                                <th class="wms-table-number">Pallets</th>
                                <th class="wms-table-actions-cell">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dispatches as $dispatch)
                                <tr>
                                    <td>
                                        <div class="dispatch-code-cell">
                                            <strong>{{ $dispatch->dispatchNumber() }}</strong>
                                            <span>{{ $dispatch->created_at?->format('d/m/Y H:i') ?? '-' }}</span>
                                        </div>
                                    </td>
                                    <td>{{ $dispatch->client?->name ?? 'Sin cliente' }}</td>
                                    <td>{{ $dispatch->type === \App\Models\GoodsDispatch::TYPE_REQUEST ? 'Desde solicitud' : 'Manual' }}</td>
                                    <td>
                                        @if ($dispatch->merchandiseRequest)
                                            <span class="wms-line-type-pill wms-line-type-pill--pallet">
                                                {{ $dispatch->merchandiseRequest->referenceCode() }}
                                            </span>
                                        @else
                                            <span class="wms-muted-value">Sin pedido</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="wms-status-chip wms-status-chip--{{ $dispatch->status }} dispatch-status dispatch-status--{{ $dispatch->status }}">
                                            {{ $dispatch->statusLabel() }}
                                        </span>
                                    </td>
                                    <td class="wms-table-number">{{ number_format($dispatch->palletsCount(), 0, ',', '.') }}</td>
                                    <td>
                                        <div class="wms-row-actions">
                                            <a href="{{ route('dispatches.show', $dispatch) }}" class="button-secondary compact-button btn-table">Ver salida</a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($dispatches->hasPages())
            <div class="pagination-card surface-card compact-card">
                {{ $dispatches->links() }}
            </div>
        @endif
    </div>
@endsection





