@extends('layouts.dashboard')

@section('title', 'Panel de control | MAXIMO WMS')
@section('topbar_title', 'Panel de control')

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (session('warning'))
        <div class="alert alert-error">{{ session('warning') }}</div>
    @endif

    <section class="surface-card ops-page-header ops-page-header--dense compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Panel de control</h2>
            <span class="ops-page-meta">{{ $visibleModuleCount }} modulos visibles</span>
        </div>

        <div class="ops-page-actions page-actions-compact action-buttons">
            <span class="ops-status badge-compact">{{ $currentRoleName }}</span>
        </div>
    </section>

    <section class="ops-section-grid ops-section-grid--dashboard" aria-label="Secciones operativas">
        @foreach ($navigationSections as $section)
            <article class="surface-card ops-index-card compact-card">
                <div class="ops-section-heading ops-index-heading">
                    <strong>{{ $section['title'] }}</strong>
                    <span class="ops-status badge-compact">{{ count($section['children']) }}</span>
                </div>

                <div class="ops-index-list">
                    @foreach ($section['children'] as $child)
                        <a href="{{ route($child['route']) }}" class="ops-index-link{{ request()->routeIs(...($child['active_patterns'] ?? [$child['route']])) ? ' is-active' : '' }}">
                            <strong>{{ $child['title'] }}</strong>
                            <span class="ops-status badge-compact {{ $child['status'] === 'ready' ? 'ops-status--ready' : 'ops-status--placeholder' }}">
                                {{ $child['status_label'] }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </article>
        @endforeach
    </section>

    @if ($showGoogleCalendarControls && $googleCalendarStatus !== null)
        <section class="surface-card compact-card dashboard-google-connection-card">
            <div class="dashboard-google-connection-head">
                <div class="dashboard-notifications-intro">
                    <strong>Google Calendar</strong>
                    <p>Integracion OAuth en modo solo lectura para la agenda operativa.</p>
                </div>
                <span class="status-badge dashboard-google-status dashboard-google-status--{{ $googleCalendarStatus['state'] }}">
                    {{ $googleCalendarStatus['label'] }}
                </span>
            </div>

            <p class="dashboard-google-connection-copy">{{ $googleCalendarStatus['message'] }}</p>

            <div class="action-buttons dashboard-google-connection-actions">
                @if ($googleCalendarStatus['state'] !== 'disabled')
                    <a href="{{ route('google-calendar.oauth.redirect') }}" class="button-primary compact-button btn-compact">Conectar Google Calendar</a>
                @endif

                @if ($googleCalendarStatus['state'] === 'connected')
                    <form method="POST" action="{{ route('google-calendar.oauth.disconnect') }}">
                        @csrf
                        <button type="submit" class="button-secondary compact-button btn-compact">Desconectar Google Calendar</button>
                    </form>
                @endif
            </div>

            <p class="helper-text">TODO: mapear colores por cliente/tipo operacion y reconciliacion booking WMS vs evento Google.</p>
        </section>
    @endif

    <section class="surface-card compact-card dashboard-calendar-card">
        <div class="ops-section-heading dashboard-notifications-header">
            <div class="dashboard-notifications-intro">
                <strong>Calendario de bookings</strong>
                <p class="merchandise-request-summary-copy">
                    Semana operativa del {{ $bookingCalendarStart->format('d/m') }} al {{ $bookingCalendarEnd->format('d/m') }}.
                </p>
            </div>
            <a href="{{ route('bookings.calendar') }}" class="button-secondary compact-button btn-table dashboard-notifications-link">Abrir agenda</a>
        </div>

        <div class="dashboard-booking-calendar-grid">
            @foreach ($bookingCalendarDays as $day)
                <article class="dashboard-booking-day">
                    <div class="dashboard-booking-day-head">
                        <strong>{{ \Illuminate\Support\Str::ucfirst($day['date']->locale('es')->isoFormat('dddd')) }}</strong>
                        <span>{{ $day['date']->format('d/m') }}</span>
                    </div>

                    @if ($day['bookings']->isEmpty() && $day['google_events']->isEmpty())
                        <div class="dashboard-booking-day-empty">Sin actividad</div>
                    @else
                        <div class="dashboard-booking-day-list">
                            @foreach ($day['bookings'] as $booking)
                                <a href="{{ route('bookings.show', $booking) }}" class="dashboard-booking-chip dashboard-booking-chip--{{ $booking->status }}">
                                    <strong>{{ $booking->referenceCode() }}</strong>
                                    <span>{{ $booking->typeLabel() }} - {{ $booking->client?->name ?? 'Sin cliente' }}</span>
                                    <span>{{ $booking->carrier_name ?: 'Sin transportista' }}</span>
                                </a>
                            @endforeach

                            @if ($showGoogleCalendarLayer)
                                @foreach ($day['google_events'] as $googleEvent)
                                    <article class="dashboard-google-event-chip">
                                        <span class="dashboard-google-event-badge">Google</span>
                                        <strong>{{ $googleEvent['title'] }}</strong>
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
                    @endif
                </article>
            @endforeach
        </div>

        <p class="helper-text">TODO: crear eventos Google al aprobar booking, actualizarlos al modificar booking y cancelarlos cuando el booking se anule.</p>
    </section>

    <section class="surface-card compact-card dashboard-notifications-card">
        <div class="ops-section-heading dashboard-notifications-header">
            <div class="dashboard-notifications-intro">
                <strong>Proximos bookings</strong>
                <p class="merchandise-request-summary-copy">Agenda operativa interna y proximas solicitudes previstas.</p>
            </div>
            <a href="{{ route('bookings.calendar') }}" class="button-secondary compact-button btn-table dashboard-notifications-link">Ver agenda</a>
        </div>

        @if ($upcomingBookings->isEmpty())
            <div class="merchandise-request-summary-empty dashboard-notifications-empty">
                No hay bookings proximos para este usuario.
            </div>
        @else
            <div class="dashboard-notification-list">
                @foreach ($upcomingBookings as $booking)
                    <article class="dashboard-notification-item">
                        <strong>{{ $booking->referenceCode() }} - {{ $booking->typeLabel() }}</strong>
                        <p>{{ $booking->scheduledWindowLabel() }} - {{ $booking->client?->name ?? 'Sin cliente' }}</p>
                        <div class="dashboard-notification-meta">
                            <span class="ops-status badge-compact">{{ number_format($booking->pallets_expected ?? 0, 0, ',', '.') }} pallets</span>
                            <span>{{ $booking->statusLabel() }}</span>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="surface-card compact-card dashboard-notifications-card">
        <div class="ops-section-heading dashboard-notifications-header">
            <div class="dashboard-notifications-intro">
                <strong>Notificaciones recientes</strong>
                <p class="merchandise-request-summary-copy">Avisos internos y seguimiento operativo reciente en el SGA.</p>
            </div>
            <a href="{{ route('notifications.index') }}" class="button-secondary compact-button btn-table dashboard-notifications-link">Ver todas</a>
        </div>

        @if ($recentNotifications->isEmpty())
            <div class="merchandise-request-summary-empty dashboard-notifications-empty">
                No hay notificaciones recientes para este usuario.
            </div>
        @else
            <div class="dashboard-notification-list">
                @foreach ($recentNotifications as $notification)
                    <article class="dashboard-notification-item{{ $notification->read_at === null ? ' is-unread' : '' }}">
                        <strong>{{ $notification->data['title'] ?? 'Notificacion' }}</strong>
                        <p>{{ $notification->data['body'] ?? 'Sin detalle adicional.' }}</p>
                        <div class="dashboard-notification-meta">
                            <span class="ops-status badge-compact">{{ $notification->read_at === null ? 'Pendiente' : 'Leida' }}</span>
                            <span>{{ $notification->created_at?->format('d/m/Y H:i') }}</span>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
