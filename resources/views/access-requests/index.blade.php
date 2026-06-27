@extends('layouts.dashboard')

@section('title', 'Solicitudes de acceso | MAXIMO WMS')
@section('topbar_title', 'Solicitudes de acceso')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Sistema</span>
        <span>/</span>
        <span>Solicitudes de acceso</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Solicitudes de acceso</h2>
            <span class="ops-page-meta">{{ $accessRequests->total() }} registros</span>
        </div>
        <div class="access-request-summary">
            <span class="status-badge status-badge--warning">{{ $pendingCount }} pendientes</span>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('access-requests.index') }}" class="item-filter-form compact-filters filters-compact users-filter-form">
            <label class="auth-field">
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

            <div class="item-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('access-requests.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($accessRequests->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin resultados</span>
            <h3>No hay solicitudes con estos filtros</h3>
            <p>Ajusta el estado o la busqueda para localizar altas pendientes.</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact" aria-label="Listado de solicitudes de acceso">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Nombre</th>
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
                                <td>{{ $accessRequest->name }}</td>
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
                                    <a href="{{ route('access-requests.show', $accessRequest) }}" class="button-secondary compact-button btn-table">Ver</a>
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
@endsection
