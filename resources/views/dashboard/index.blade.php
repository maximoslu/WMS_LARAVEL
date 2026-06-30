@extends('layouts.dashboard')

@section('title', 'Panel de control | MAXIMO WMS')
@section('topbar_title', 'Panel de control')

@section('content')
    <section class="surface-card ops-page-header ops-page-header--dense compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Panel de control</h2>
            <span class="ops-page-meta">{{ $visibleModuleCount }} módulos visibles</span>
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

        @if ($bookingCalendarDays->every(fn (array $day): bool => $day['bookings']->isEmpty()))
            <div class="dashboard-notifications-empty">
                No hay bookings previstos.
            </div>
        @else
            <div class="dashboard-booking-calendar-grid">
                @foreach ($bookingCalendarDays as $day)
                    <article class="dashboard-booking-day">
                        <div class="dashboard-booking-day-head">
                            <strong>{{ $day['date']->translatedFormat('l') }}</strong>
                            <span>{{ $day['date']->format('d/m') }}</span>
                        </div>

                        @if ($day['bookings']->isEmpty())
                            <div class="dashboard-booking-day-empty">Sin bookings</div>
                        @else
                            <div class="dashboard-booking-day-list">
                                @foreach ($day['bookings'] as $booking)
                                    <a href="{{ route('bookings.show', $booking) }}" class="dashboard-booking-chip dashboard-booking-chip--{{ $booking->status }}">
                                        <strong>{{ $booking->referenceCode() }}</strong>
                                        <span>{{ $booking->typeLabel() }} · {{ $booking->client?->name ?? 'Sin cliente' }}</span>
                                        <span>{{ $booking->carrier_name ?: 'Sin transportista' }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        @if (filled($googleBookingCalendarEmbedUrl))
            <article class="dashboard-google-calendar">
                <div class="ops-section-heading dashboard-notifications-header">
                    <div class="dashboard-notifications-intro">
                        <strong>Calendario Google Workspace</strong>
                        <p class="merchandise-request-summary-copy">Vista compartida opcional del calendario corporativo.</p>
                    </div>
                </div>
                <div class="dashboard-google-calendar-frame">
                    <iframe
                        src="{{ $googleBookingCalendarEmbedUrl }}"
                        title="Calendario Google Workspace de bookings"
                        loading="lazy"
                        referrerpolicy="no-referrer"
                    ></iframe>
                </div>
                <p class="helper-text">TODO: preparar sincronización real mediante API de Google Workspace cuando exista infraestructura corporativa.</p>
            </article>
        @endif
    </section>

    <section class="surface-card compact-card dashboard-notifications-card">
        <div class="ops-section-heading dashboard-notifications-header">
            <div class="dashboard-notifications-intro">
                <strong>Próximos bookings</strong>
                <p class="merchandise-request-summary-copy">Agenda operativa interna y próximas solicitudes previstas.</p>
            </div>
            <a href="{{ route('bookings.calendar') }}" class="button-secondary compact-button btn-table dashboard-notifications-link">Ver agenda</a>
        </div>

        @if ($upcomingBookings->isEmpty())
            <div class="merchandise-request-summary-empty dashboard-notifications-empty">
                No hay bookings próximos para este usuario.
            </div>
        @else
            <div class="dashboard-notification-list">
                @foreach ($upcomingBookings as $booking)
                    <article class="dashboard-notification-item">
                        <strong>{{ $booking->referenceCode() }} · {{ $booking->typeLabel() }}</strong>
                        <p>{{ $booking->scheduledWindowLabel() }} · {{ $booking->client?->name ?? 'Sin cliente' }}</p>
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
                        <strong>{{ $notification->data['title'] ?? 'Notificación' }}</strong>
                        <p>{{ $notification->data['body'] ?? 'Sin detalle adicional.' }}</p>
                        <div class="dashboard-notification-meta">
                            <span class="ops-status badge-compact">{{ $notification->read_at === null ? 'Pendiente' : 'Leída' }}</span>
                            <span>{{ $notification->created_at?->format('d/m/Y H:i') }}</span>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
