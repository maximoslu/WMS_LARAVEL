@extends('layouts.dashboard')

@section('title', 'Bookings | MAXIMO WMS')
@section('topbar_title', 'Bookings')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Operaciones</span>
        <span>/</span>
        <span>Bookings</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">{{ $isClient ? 'Mis bookings' : 'Bookings operativos' }}</h2>
            <span class="ops-page-meta">{{ $bookings->total() }} registros</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons">
            <a href="{{ route('bookings.calendar') }}" class="button-secondary compact-button btn-compact">Ver agenda</a>
            @if ($canCreate)
                <a href="{{ route('bookings.create') }}" class="button-primary compact-button btn-compact">Solicitar booking</a>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('bookings.index') }}" class="item-filter-form compact-filters filters-compact">
            @unless ($isClient)
                <label class="auth-field">
                    <span>Cliente</span>
                    <select name="client_id" class="auth-input">
                        <option value="">Todos los clientes</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((string) $filters['client_id'] === (string) $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endunless

            <label class="auth-field">
                <span>Fecha desde</span>
                <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Fecha hasta</span>
                <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="auth-input">
            </label>

            <label class="auth-field">
                <span>Estado</span>
                <select name="status" class="auth-input">
                    <option value="all">Todos</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="auth-field">
                <span>Tipo</span>
                <select name="type" class="auth-input">
                    <option value="all">Todos</option>
                    @foreach ($typeOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="auth-field">
                <span>Código, transportista o matrícula</span>
                <input type="text" name="search" value="{{ $filters['search'] }}" class="auth-input" placeholder="Buscar booking">
            </label>

            <div class="item-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Filtrar</button>
                <a href="{{ route('bookings.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($bookings->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin bookings</span>
            <h3>{{ $isClient ? 'Todavía no has solicitado bookings' : 'No hay bookings con estos filtros' }}</h3>
            <p>{{ $isClient ? 'Cuando registres una nueva entrada o salida prevista aparecerá aquí para su seguimiento.' : 'Ajusta los filtros para localizar bookings operativos.' }}</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact" aria-label="Listado de bookings">
                    <thead>
                        <tr>
                            <th>Código</th>
                            @unless ($isClient)
                                <th>Cliente</th>
                            @endunless
                            <th>Tipo</th>
                            <th>Fecha / hora</th>
                            <th>Pallets</th>
                            <th>Transportista</th>
                            <th>Matrícula</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings as $booking)
                            <tr>
                                <td><strong>{{ $booking->referenceCode() }}</strong></td>
                                @unless ($isClient)
                                    <td>{{ $booking->client?->name ?? 'Sin cliente' }}</td>
                                @endunless
                                <td>{{ $booking->typeLabel() }}</td>
                                <td>{{ $booking->scheduledWindowLabel() }}</td>
                                <td>{{ number_format($booking->pallets_expected ?? 0, 0, ',', '.') }}</td>
                                <td>{{ $booking->carrier_name ?: '-' }}</td>
                                <td>{{ $booking->vehicle_plate ?: '-' }}</td>
                                <td>
                                    <span class="status-badge merchandise-request-status merchandise-request-status--{{ $booking->status }}">
                                        {{ $booking->statusLabel() }}
                                    </span>
                                </td>
                                <td>
                                    <div class="inline-actions action-buttons">
                                        <a href="{{ route('bookings.show', $booking) }}" class="button-secondary compact-button btn-table">Ver detalle</a>
                                        <a href="{{ route('bookings.edit', $booking) }}" class="button-secondary compact-button btn-table">Editar</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($bookings->hasPages())
        <div class="pagination-card surface-card compact-card">
            {{ $bookings->links() }}
        </div>
    @endif
@endsection
