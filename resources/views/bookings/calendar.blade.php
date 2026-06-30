@extends('layouts.dashboard')

@section('title', 'Agenda de bookings | MAXIMO WMS')
@section('topbar_title', 'Agenda de bookings')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <a href="{{ route('bookings.index') }}">Bookings</a>
        <span>/</span>
        <span>Agenda</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">{{ $isClient ? 'Agenda de mis bookings' : 'Calendario operativo de bookings' }}</h2>
            <span class="ops-page-meta">Vista agenda agrupada por fecha</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons">
            <a href="{{ route('bookings.index') }}" class="button-secondary compact-button btn-compact">Ver listado</a>
        </div>
    </section>

    <section class="surface-card item-filter-card compact-card">
        <form method="GET" action="{{ route('bookings.calendar') }}" class="item-filter-form compact-filters filters-compact">
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

            <div class="item-filter-actions action-buttons page-actions-compact">
                <button type="submit" class="button-primary compact-button btn-compact">Actualizar agenda</button>
            </div>
        </form>
    </section>

    @if ($groupedBookings->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin bookings</span>
            <h3>No hay bookings en la ventana seleccionada</h3>
            <p>La agenda mostrará aquí los próximos movimientos previstos por día.</p>
        </article>
    @else
        <section class="ops-section-grid ops-section-grid--dashboard booking-calendar-agenda">
            @foreach ($groupedBookings as $date => $bookings)
                <article class="surface-card ops-index-card compact-card booking-calendar-day-card">
                    <div class="ops-section-heading ops-index-heading">
                        <strong>{{ \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') }}</strong>
                        <span class="ops-status badge-compact">{{ $bookings->count() }}</span>
                    </div>

                    <div class="dashboard-notification-list booking-calendar-day-list">
                        @foreach ($bookings as $booking)
                            <a href="{{ route('bookings.show', $booking) }}" class="dashboard-booking-chip dashboard-booking-chip--{{ $booking->status }}">
                                <strong>{{ $booking->referenceCode() }} · {{ $booking->typeLabel() }}</strong>
                                <span>{{ $booking->scheduledWindowLabel() }}</span>
                                <span>{{ $booking->client?->name }} · {{ $booking->carrier_name ?: 'Sin transportista' }} · {{ number_format($booking->pallets_expected ?? 0, 0, ',', '.') }} pallets</span>
                                <span class="ops-status badge-compact">{{ $booking->statusLabel() }}</span>
                            </a>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </section>
    @endif
@endsection
