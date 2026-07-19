@extends('layouts.dashboard')

@section('title', 'Solicitudes de acceso | MAXIMO WMS')
@section('topbar_title', 'Solicitudes de acceso')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Sistema'],
            ['label' => 'Solicitudes de acceso'],
        ];

        $visibleRequests = $accessRequests->getCollection();
        $visiblePendingRequests = $visibleRequests->where('status', \App\Models\AccessRequest::STATUS_PENDING)->count();
        $visibleResolvedRequests = $accessRequests->count() - $visiblePendingRequests;
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-admin-page wms-access-request-page">
        <section class="surface-card compact-card wms-list-header wms-admin-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Control de altas</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Solicitudes de acceso</h2>
                    <span class="wms-list-count">{{ $accessRequests->total() }} registros</span>
                </div>
                <p class="wms-list-subtitle">
                    Bandeja administrativa para revisar solicitudes, identificar solicitante y abrir la aprobacion o rechazo sin ejecutar acciones desde el listado.
                </p>
            </div>

            <div class="wms-admin-header-side">
                <dl class="wms-list-metrics wms-admin-metrics">
                    <div>
                        <dt>Pendientes</dt>
                        <dd>{{ $pendingCount }}</dd>
                    </div>
                    <div>
                        <dt>Visibles</dt>
                        <dd>{{ $accessRequests->count() }}</dd>
                    </div>
                    <div>
                        <dt>Resueltas</dt>
                        <dd>{{ $visibleResolvedRequests }}</dd>
                    </div>
                </dl>

                <div class="access-request-summary">
                    <span class="status-badge status-badge--warning">{{ $pendingCount }} pendientes</span>
                </div>
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <section class="surface-card compact-card wms-filter-panel wms-admin-filter-panel">
            <form method="GET" action="{{ route('access-requests.index') }}" class="item-filter-form compact-filters filters-compact users-filter-form wms-filter-grid wms-admin-filter-grid wms-admin-filter-grid--access">
                <label class="auth-field wms-filter-search">
                    <span>Nombre, empresa o email</span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="auth-input" placeholder="Buscar solicitud">
                </label>

                <label class="auth-field">
                    <span>Estado</span>
                    <select name="status" class="auth-input">
                        <option value="pending" @selected($filters['status'] === 'pending')>Pendientes</option>
                        <option value="approved" @selected($filters['status'] === 'approved')>Aprobadas</option>
                        <option value="rejected" @selected($filters['status'] === 'rejected')>Rechazadas</option>
                    </select>
                </label>

                <div class="item-filter-actions action-buttons page-actions-compact wms-filter-actions">
                    <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                    <a href="{{ route('access-requests.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                </div>
            </form>

            <div class="wms-filter-summary">
                @if ($filters['search'])
                    <span class="wms-filter-token">Busqueda: {{ $filters['search'] }}</span>
                @endif
                <span class="wms-filter-muted">Estado: {{ match($filters['status']) {
                    'approved' => 'Aprobadas',
                    'rejected' => 'Rechazadas',
                    default => 'Pendientes',
                } }}</span>
                <span class="wms-filter-muted">{{ $visiblePendingRequests }} pendientes visibles</span>
            </div>
        </section>

        @if ($accessRequests->isEmpty())
            <article class="surface-card compact-card wms-empty-state wms-admin-empty">
                <div>
                    <span class="wms-status-chip wms-status-chip--neutral">Sin resultados</span>
                    <h3>No hay solicitudes con estos filtros</h3>
                    <p>Ajusta el estado o la busqueda para localizar solicitudes de acceso pendientes.</p>
                </div>
            </article>
        @else
            <section class="surface-card compact-card wms-table-panel wms-admin-table-panel">
                <div class="wms-table-toolbar">
                    <div>
                        <strong>Bandeja de solicitudes</strong>
                        <span>Mostrando {{ $accessRequests->firstItem() }}-{{ $accessRequests->lastItem() }} de {{ $accessRequests->total() }}</span>
                    </div>
                    <div class="wms-table-totals">
                        <span>{{ $pendingCount }} pendientes totales</span>
                    </div>
                </div>

                <div class="data-table-wrap">
                    <table class="data-table table-compact wms-admin-table wms-access-request-table" aria-label="Listado de solicitudes de acceso">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Solicitante</th>
                                <th>Empresa</th>
                                <th>Email</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accessRequests as $accessRequest)
                                <tr>
                                    <td>{{ $accessRequest->created_at?->format('Y-m-d H:i') }}</td>
                                    <td>
                                        <div class="stock-cell-main wms-admin-identity">
                                            <strong>{{ $accessRequest->name }}</strong>
                                            <span class="users-table-email">{{ $accessRequest->client?->name ?? 'Sin cliente asignado' }}</span>
                                        </div>
                                    </td>
                                    <td>{{ $accessRequest->company ?: 'Sin empresa' }}</td>
                                    <td>{{ $accessRequest->email }}</td>
                                    <td>
                                        <span class="status-badge {{ 'access-request-status access-request-status--'.$accessRequest->status }}">
                                            {{ match($accessRequest->status) {
                                                'pending' => 'Pendiente',
                                                'approved' => 'Aprobada',
                                                'rejected' => 'Rechazada',
                                                default => ucfirst($accessRequest->status),
                                            } }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="inline-actions action-buttons wms-row-actions">
                                            <a href="{{ route('access-requests.show', $accessRequest) }}" class="button-secondary compact-button btn-table">Ver</a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($accessRequests->hasPages())
            <div class="pagination-card surface-card compact-card">
                {{ $accessRequests->links() }}
            </div>
        @endif
    </div>
@endsection





