@extends('layouts.dashboard')

@section('title', 'Solicitudes | MAXIMO WMS')
@section('topbar_title', 'Solicitudes')

@section('content')
    @php
        $breadcrumbs = [


        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => 'Operaciones'],
        ['label' => $isClient ? 'Solicitudes' : 'Bookings'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact compact-card wms-page-hero">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">{{ $isClient ? 'Mis solicitudes de mercancia' : 'Bookings operativos' }}</h2>
            <span class="ops-page-meta">{{ $isClient ? 'Consulta, filtra y crea nuevas solicitudes de entrada o salida.' : $bookings->total() . ' registros operativos' }}</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons">
            <span class="wms-counter-pill">{{ $bookings->total() }} {{ $isClient ? 'solicitudes' : 'registros' }}</span>
            <a href="{{ route('bookings.calendar') }}" class="button-secondary compact-button btn-compact wms-action-secondary">Ver agenda</a>
            @if ($canCreate)
                <a href="{{ route('bookings.create') }}" class="button-primary compact-button btn-compact wms-action-primary">Nueva solicitud</a>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (session('warning'))
        <div class="alert alert-error">{{ session('warning') }}</div>
    @endif

    <section class="surface-card item-filter-card compact-card wms-filter-card">
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
                <span>{{ $isClient ? 'Codigo o referencia' : 'Codigo, transportista o matricula' }}</span>
                <input type="text" name="search" value="{{ $filters['search'] }}" class="auth-input" placeholder="{{ $isClient ? 'Buscar solicitud' : 'Buscar booking' }}">
            </label>

            <div class="item-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact wms-action-primary">Filtrar</button>
                <a href="{{ route('bookings.index') }}" class="button-secondary compact-button btn-compact wms-action-secondary">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($bookings->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">{{ $isClient ? 'Sin solicitudes' : 'Sin bookings' }}</span>
            <h3>{{ $isClient ? 'Todavia no has registrado solicitudes' : 'No hay bookings con estos filtros' }}</h3>
            <p>{{ $isClient ? 'Cuando registres una nueva entrada o salida prevista aparecera aqui para su seguimiento.' : 'Ajusta los filtros para localizar bookings operativos.' }}</p>
        </article>
    @else
        <section class="surface-card stock-table-shell compact-card">
            <div class="data-table-wrap">
                <table class="data-table table-compact wms-data-table" aria-label="Listado de bookings">
                    <thead>
                        <tr>
                            <th>Codigo</th>
                            @unless ($isClient)
                                <th>Cliente</th>
                            @endunless
                            <th>Tipo</th>
                            <th>Fecha / hora</th>
                            <th>Pallets</th>
                            @unless ($isClient)
                                <th>Transportista</th>
                                <th>Matricula</th>
                            @endunless
                            <th>Estado</th>
                            @unless ($isClient)
                                <th>Google Calendar</th>
                            @endunless
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
                                @unless ($isClient)
                                    <td>{{ $booking->carrier_name ?: '-' }}</td>
                                    <td>{{ $booking->vehicle_plate ?: 'Pendiente' }}</td>
                                @endunless
                                <td>
                                    <span class="status-badge merchandise-request-status merchandise-request-status--{{ $booking->status }} wms-status-badge">
                                        {{ $booking->statusLabel() }}
                                    </span>
                                </td>
                                @unless ($isClient)
                                    @php
                                        $googleState = match ($booking->googleCalendarSyncState()) {
                                            'synced', 'cancelled' => 'connected',
                                            'error' => 'error',
                                            default => 'pending',
                                        };
                                    @endphp
                                    <td>
                                        <span class="status-badge dashboard-google-status dashboard-google-status--{{ $googleState }}">
                                            {{ $booking->googleCalendarSyncLabel() }}
                                        </span>
                                    </td>
                                @endunless
                                <td>
                                    <div class="inline-actions action-buttons">
                                        <a href="{{ route('bookings.show', $booking) }}" class="button-secondary compact-button btn-table">Ver</a>
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





