@extends('layouts.dashboard')

@section('title', 'Agenda operativa | MAXIMO WMS')
@section('topbar_title', 'Agenda operativa')

@section('content')
    @php
        $breadcrumbs = [


        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => $isClient ? 'Solicitudes' : 'Bookings', 'href' => route('bookings.index')],
        ['label' => 'Agenda'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact compact-card wms-page-hero">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">{{ $isClient ? 'Agenda de solicitudes' : 'Agenda operativa' }}</h2>
            <span class="ops-page-meta">{{ $isClient ? 'Consulta tus solicitudes planificadas.' : 'Bookings WMS y eventos de Google Calendar en una vista operativa.' }}</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons">
            <a href="{{ route('bookings.index') }}" class="button-secondary compact-button btn-compact">Ver listado</a>
        </div>
    </section>

    <section class="surface-card item-filter-card compact-card wms-filter-card">
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
                <button type="submit" class="button-primary compact-button btn-compact wms-action-primary">Filtrar</button>
                <a href="{{ route('bookings.calendar') }}" class="button-secondary compact-button btn-compact wms-action-secondary">Limpiar</a>
            </div>
        </form>
    </section>

    @if ($showGoogleCalendarControls && $googleCalendarStatus !== null)
        <section class="surface-card compact-card wms-google-agenda-card">
            <div class="wms-google-agenda-head">
                <div class="dashboard-notifications-intro">
                    <strong>Agenda Google</strong>
                    <p class="merchandise-request-summary-copy">Integrada en la agenda operativa.</p>
                </div>
                <span class="status-badge dashboard-google-status dashboard-google-status--{{ $googleCalendarStatus['state'] }}">
                    {{
                        match ($googleCalendarStatus['state']) {
                            'connected' => 'Conectada',
                            'pending' => 'Pendiente de conectar',
                            'disabled' => 'Desactivada',
                            default => 'Error de configuracion',
                        }
                    }}
                </span>
            </div>

            <div class="action-buttons">
                @if ($googleCalendarStatus['state'] !== 'disabled')
                    <a href="{{ route('google-calendar.oauth.redirect') }}" class="button-primary compact-button btn-compact wms-action-primary">Conectar</a>
                @endif

                @if ($googleCalendarStatus['state'] === 'connected')
                    <form method="POST" action="{{ route('google-calendar.oauth.disconnect') }}">
                        @csrf
                        <button type="submit" class="button-secondary compact-button btn-compact wms-action-secondary">Desconectar</button>
                    </form>
                @endif
            </div>
        </section>
    @endif

    @if ($calendarDays->every(fn (array $day) => $day['bookings']->isEmpty() && $day['google_events']->isEmpty()))
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin actividad</span>
            <h3>No hay actividad en la ventana seleccionada</h3>
            <p>La agenda mostrara aqui las solicitudes y eventos previstos por dia.</p>
        </article>
    @else
        <section class="ops-section-grid ops-section-grid--dashboard booking-calendar-agenda">
            @foreach ($calendarDays as $day)
                <article class="surface-card ops-index-card compact-card booking-calendar-day-card">
                    <div class="ops-section-heading ops-index-heading">
                        <strong>{{ \Illuminate\Support\Str::ucfirst($day['date']->locale('es')->isoFormat('dddd D [de] MMMM')) }}</strong>
                        <span class="ops-status badge-compact">{{ $day['bookings']->count() + ($showGoogleCalendarLayer ? $day['google_events']->count() : 0) }}</span>
                    </div>

                    <div class="dashboard-notification-list booking-calendar-day-list">
                        @if ($day['bookings']->isEmpty() && (! $showGoogleCalendarLayer || $day['google_events']->isEmpty()))
                            <div class="dashboard-booking-day-empty">Sin actividad</div>
                        @endif

                        @foreach ($day['bookings'] as $booking)
                            <a href="{{ route('bookings.show', $booking) }}" class="dashboard-booking-chip dashboard-booking-chip--{{ $booking->status }}">
                                <div class="wms-calendar-chip-top">
                                    <span class="wms-calendar-source wms-calendar-source-wms">WMS</span>
                                    <strong>{{ $booking->referenceCode() }} - {{ $booking->typeLabel() }}</strong>
                                </div>
                                <span>{{ $booking->scheduledWindowLabel() }}</span>
                                <span>{{ $booking->client?->name }} - {{ number_format($booking->pallets_expected ?? 0, 0, ',', '.') }} pallets</span>
                                <span class="ops-status badge-compact">{{ $booking->statusLabel() }}</span>
                            </a>
                        @endforeach

                        @if ($showGoogleCalendarLayer)
                            @foreach ($day['google_events'] as $googleEvent)
                                <article class="dashboard-google-event-chip">
                                    <div class="wms-calendar-chip-top">
                                        <span class="wms-calendar-source wms-calendar-source-google">Google</span>
                                        <strong>{{ $googleEvent['title'] }}</strong>
                                    </div>
                                    <span>
                                        {{ $googleEvent['all_day'] ? 'Todo el dia' : $googleEvent['starts_at']->format('H:i') . ' - ' . $googleEvent['ends_at']->format('H:i') }}
                                    </span>
                                    @if ($googleEvent['location'])
                                        <span>{{ $googleEvent['location'] }}</span>
                                    @endif
                                </article>
                            @endforeach
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
    @endif
@endsection





