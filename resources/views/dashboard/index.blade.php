@extends('layouts.dashboard')

@section('title', 'Panel de control | MAXIMO WMS')
@section('topbar_title', 'Panel de control')

@section('content')
    @php
        $dashboardPendingTotal = collect($navigationSections)
            ->flatMap(fn (array $section) => $section['children'])
            ->sum(fn (array $child) => (int) ($child['pending_count'] ?? 0));
        $dashboardAgendaBookings = $bookingCalendarDays->sum(fn (array $day) => $day['bookings']->count());
        $dashboardGoogleEvents = $showGoogleCalendarLayer
            ? $bookingCalendarDays->sum(fn (array $day) => $day['google_events']->count())
            : 0;
    @endphp

    <div class="wms-dashboard-page">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if (session('warning'))
            <div class="alert alert-error">{{ session('warning') }}</div>
        @endif

        <section class="surface-card compact-card wms-list-header wms-dashboard-header">
            <div class="wms-list-headline">
                <span class="wms-list-kicker">Inicio operativo</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Panel de control</h2>
                    <span class="wms-list-count">{{ $visibleModuleCount }} modulos visibles</span>
                </div>
                <p class="wms-list-subtitle">
                    Accesos, pendientes y agenda semanal del WMS en una vista compacta para operar sin rodeos.
                </p>
            </div>

            <div class="wms-list-actions wms-dashboard-header-actions">
                <dl class="wms-list-metrics wms-dashboard-kpis" aria-label="Resumen del panel de control">
                    <div>
                        <dt>Rol</dt>
                        <dd>{{ $currentRoleName }}</dd>
                    </div>
                    <div>
                        <dt>Pendientes</dt>
                        <dd>{{ $dashboardPendingTotal }}</dd>
                    </div>
                    <div>
                        <dt>Agenda</dt>
                        <dd>{{ $dashboardAgendaBookings }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="wms-dashboard-layout" aria-label="Panel operativo">
            <div class="wms-dashboard-main">
                <section class="ops-section-grid ops-section-grid--dashboard wms-dashboard-grid" aria-label="Secciones operativas">
                    @foreach ($navigationSections as $section)
                        @php
                            $sectionPendingTotal = collect($section['children'])->sum(fn (array $child) => (int) ($child['pending_count'] ?? 0));
                        @endphp

                        <article class="surface-card ops-index-card compact-card wms-dashboard-section wms-dashboard-section--{{ $section['key'] }}">
                            <div class="ops-section-heading ops-index-heading wms-dashboard-section-head">
                                <div>
                                    <strong>{{ $section['title'] }}</strong>
                                    @if (! empty($section['summary']))
                                        <p>{{ $section['summary'] }}</p>
                                    @endif
                                </div>
                                <span class="ops-status badge-compact">{{ count($section['children']) }}</span>
                            </div>

                            <div class="ops-index-list wms-dashboard-module-list">
                                @foreach ($section['children'] as $child)
                                    @php
                                        $pendingCount = (int) ($child['pending_count'] ?? 0);
                                        $isActive = request()->routeIs(...($child['active_patterns'] ?? [$child['route']]));
                                    @endphp

                                    <a href="{{ route($child['display_route'] ?? $child['route']) }}" class="ops-index-link{{ $isActive ? ' is-active' : '' }}{{ $pendingCount > 0 ? ' has-pending' : '' }} wms-dashboard-module wms-dashboard-module--{{ $child['key'] }}">
                                        <span class="module-link-body">
                                            <span class="module-link-icon" aria-hidden="true">
                                                <x-module-icon :name="$child['display_icon']" />
                                            </span>
                                            <span class="module-link-copy">
                                                <strong>{{ $child['display_title'] ?? $child['title'] }}</strong>
                                                @if (! empty($child['summary']))
                                                    <span>{{ $child['summary'] }}</span>
                                                @endif
                                            </span>
                                        </span>
                                        <span class="dashboard-module-status">
                                            @if ($pendingCount > 0)
                                                <span class="dashboard-module-pending badge-compact">{{ $pendingCount }} pendiente{{ $pendingCount === 1 ? '' : 's' }}</span>
                                            @endif
                                            <span class="ops-status badge-compact {{ $child['status'] === 'ready' ? 'ops-status--ready' : 'ops-status--placeholder' }}">
                                                {{ $child['status_label'] }}
                                            </span>
                                        </span>
                                    </a>
                                @endforeach
                            </div>

                            <div class="wms-dashboard-section-foot">
                                @if ($sectionPendingTotal > 0)
                                    <span class="wms-status-chip wms-status-chip--pending">{{ $sectionPendingTotal }} pendiente{{ $sectionPendingTotal === 1 ? '' : 's' }}</span>
                                @else
                                    <span class="wms-muted-value">Sin pendientes</span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </section>
            </div>

            <aside class="surface-card compact-card dashboard-calendar-card wms-dashboard-agenda" aria-label="Agenda operativa semanal">
                <div class="ops-section-heading dashboard-notifications-header wms-dashboard-agenda-head">
                    <div class="dashboard-notifications-intro">
                        <strong>{{ $isClient ? 'Agenda de BOOKING' : 'Agenda operativa WMS' }}</strong>
                        <p class="merchandise-request-summary-copy">
                            Semana operativa del {{ $bookingCalendarStart->format('d/m') }} al {{ $bookingCalendarEnd->format('d/m') }}.
                        </p>
                    </div>
                    <div class="wms-dashboard-agenda-actions">
                        <span class="wms-muted-value">{{ $dashboardAgendaBookings }} booking{{ $dashboardAgendaBookings === 1 ? '' : 's' }}</span>
                        @if ($showGoogleCalendarLayer)
                            <span class="wms-muted-value">{{ $dashboardGoogleEvents }} Google</span>
                        @endif
                        <a href="{{ route('bookings.calendar') }}" class="button-secondary compact-button btn-table dashboard-notifications-link">Abrir agenda</a>
                    </div>
                </div>

                <div class="dashboard-booking-calendar-grid wms-dashboard-calendar-grid">
                    @foreach ($bookingCalendarDays as $day)
                        @php
                            $dayBookingCount = $day['bookings']->count();
                            $dayGoogleCount = $showGoogleCalendarLayer ? $day['google_events']->count() : 0;
                            $dayHasActivity = $dayBookingCount > 0 || $dayGoogleCount > 0;
                        @endphp

                        <article class="dashboard-booking-day wms-dashboard-day{{ $dayHasActivity ? ' has-activity' : '' }}">
                            <div class="dashboard-booking-day-head wms-dashboard-day-head">
                                <div>
                                    <strong>{{ \Illuminate\Support\Str::ucfirst($day['date']->locale('es')->isoFormat('dddd')) }}</strong>
                                    <span>{{ $day['date']->format('d/m') }}</span>
                                </div>
                                @if ($dayHasActivity)
                                    <span class="wms-status-chip wms-status-chip--neutral">{{ $dayBookingCount + $dayGoogleCount }}</span>
                                @endif
                            </div>

                            @if ($day['bookings']->isEmpty() && (! $showGoogleCalendarLayer || $day['google_events']->isEmpty()))
                                <div class="dashboard-booking-day-empty">Sin actividad</div>
                            @else
                                <div class="dashboard-booking-day-list">
                                    @foreach ($day['bookings'] as $booking)
                                        <a href="{{ route('bookings.show', $booking) }}" class="dashboard-booking-chip dashboard-booking-chip--{{ $booking->status }} wms-dashboard-booking">
                                            <span class="wms-dashboard-booking-top">
                                                <strong>{{ $booking->referenceCode() }}</strong>
                                                <span class="wms-status-chip wms-status-chip--{{ $booking->status }}">{{ $booking->statusLabel() }}</span>
                                            </span>
                                            <span>{{ $booking->typeLabel() }} - {{ $booking->client?->name ?? 'Sin cliente' }}</span>
                                            <span>{{ number_format($booking->pallets_expected ?? 0, 0, ',', '.') }} pallets</span>
                                        </a>
                                    @endforeach

                                    @if ($showGoogleCalendarLayer)
                                        @foreach ($day['google_events'] as $googleEvent)
                                            <article class="dashboard-google-event-chip wms-dashboard-google-event">
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
                            @endif
                        </article>
                    @endforeach
                </div>
            </aside>
        </section>
    </div>
@endsection
