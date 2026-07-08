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

    <section class="surface-card ops-page-header ops-page-header--dense compact-card wms-page-hero">
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
                        <a href="{{ route($child['display_route'] ?? $child['route']) }}" class="ops-index-link{{ request()->routeIs(...($child['active_patterns'] ?? [$child['route']])) ? ' is-active' : '' }}">
                            <span class="module-link-body">
                                <span class="module-link-icon" aria-hidden="true">
                                    <x-module-icon :name="$child['display_icon']" />
                                </span>
                                <span class="module-link-copy">
                                    <strong>{{ $child['display_title'] ?? $child['title'] }}</strong>
                                </span>
                            </span>
                            <span class="ops-status badge-compact {{ $child['status'] === 'ready' ? 'ops-status--ready' : 'ops-status--placeholder' }}">
                                {{ $child['status_label'] }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </article>
        @endforeach
    </section>

    @if ($isClient)
        <section class="surface-card compact-card dashboard-mis-albaranes-card">
            <div class="ops-section-heading dashboard-notifications-header">
                <div class="dashboard-notifications-intro">
                    <strong>Mis albaranes</strong>
                    <p class="merchandise-request-summary-copy">Consulta los documentos de entrada clasificados por mes y proveedor.</p>
                </div>
                <a href="{{ route('client-goods-receipts.index') }}" class="button-primary compact-button btn-table dashboard-notifications-link">Ver albaranes</a>
            </div>
        </section>
    @endif

    <section class="surface-card compact-card dashboard-calendar-card">
        <div class="ops-section-heading dashboard-notifications-header">
            <div class="dashboard-notifications-intro">
                <strong>{{ $isClient ? 'Agenda de BOOKING' : 'Agenda operativa WMS' }}</strong>
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

                    @if ($day['bookings']->isEmpty() && (! $showGoogleCalendarLayer || $day['google_events']->isEmpty()))
                        <div class="dashboard-booking-day-empty">Sin actividad</div>
                    @else
                        <div class="dashboard-booking-day-list">
                            @foreach ($day['bookings'] as $booking)
                                <a href="{{ route('bookings.show', $booking) }}" class="dashboard-booking-chip dashboard-booking-chip--{{ $booking->status }}">
                                    <strong>{{ $booking->referenceCode() }}</strong>
                                    <span>{{ $booking->typeLabel() }} - {{ $booking->client?->name ?? 'Sin cliente' }}</span>
                                    <span>{{ number_format($booking->pallets_expected ?? 0, 0, ',', '.') }} pallets</span>
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
                    @endif
                </article>
            @endforeach
        </div>
    </section>

@endsection

