@extends('layouts.dashboard')

@section('title', 'Panel de control | MAXIMO WMS')
@section('topbar_title', 'Panel de control')

@section('content')
    @php
        $dashboardModules = collect($navigationSections)
            ->flatMap(fn (array $section) => $section['children'])
            ->values();
        $dashboardModulesByKey = $dashboardModules->keyBy('key');
        $dashboardPendingModules = $dashboardModules
            ->filter(fn (array $child) => (int) ($child['pending_count'] ?? 0) > 0)
            ->values();
        $dashboardPendingTotal = $dashboardPendingModules
            ->sum(fn (array $child) => (int) ($child['pending_count'] ?? 0));
        $dashboardAgendaBookings = $bookingCalendarDays->sum(fn (array $day) => $day['bookings']->count());
        $dashboardGoogleEvents = $showGoogleCalendarLayer
            ? $bookingCalendarDays->sum(fn (array $day) => $day['google_events']->count())
            : 0;
        $dashboardAgendaTotal = $dashboardAgendaBookings + $dashboardGoogleEvents;
        $dashboardQuickActions = collect([
            [
                'module' => 'solicitudes',
                'route' => $isClient ? 'merchandise-requests.create' : 'merchandise-requests.index',
                'label' => $isClient ? 'Nuevo pedido' : 'Pedidos',
                'hint' => $isClient ? 'Crear solicitud' : 'Revisar operativa',
                'icon' => 'orders',
            ],
            [
                'module' => 'entradas',
                'route' => 'goods-receipts.create',
                'label' => 'Nueva entrada',
                'hint' => 'Registrar recepción',
                'icon' => 'receipts',
            ],
            [
                'module' => 'stock',
                'route' => 'stock.index',
                'label' => 'Stock',
                'hint' => 'Consultar existencias',
                'icon' => 'stock',
            ],
            [
                'module' => 'stock-relocations',
                'route' => 'stock.relocations.create',
                'label' => 'Reubicar',
                'hint' => 'Mover partidas',
                'icon' => 'locations',
            ],
            [
                'module' => 'stock-adjustments',
                'route' => 'stock.adjustments.create',
                'label' => 'Regularizar',
                'hint' => 'Ajuste controlado',
                'icon' => 'audit',
            ],
            [
                'module' => 'labels',
                'route' => 'labels.index',
                'label' => 'Etiquetas',
                'hint' => 'Preparar impresión',
                'icon' => 'receipts',
            ],
            [
                'module' => 'backups',
                'route' => 'backups.index',
                'label' => 'Backups',
                'hint' => 'Control de copias',
                'icon' => 'backups',
            ],
            [
                'module' => 'bookings',
                'route' => 'bookings.calendar',
                'label' => 'Agenda',
                'hint' => 'Planificación semanal',
                'icon' => 'booking-calendar',
            ],
        ])->filter(fn (array $action) => $dashboardModulesByKey->has($action['module']) && \Illuminate\Support\Facades\Route::has($action['route']));
    @endphp

    <div class="wms-command-dashboard">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if (session('warning'))
            <div class="alert alert-error">{{ session('warning') }}</div>
        @endif

        <section class="surface-card wms-command-hero" aria-labelledby="dashboard-title">
            <div class="wms-command-hero-copy">
                <span class="wms-command-kicker">Centro de mando WMS</span>
                <h1 id="dashboard-title">Panel de control</h1>
                <p>Prioridades, accesos operativos y planificación semanal en una sola vista.</p>
                <span class="wms-command-week">
                    Semana del {{ $bookingCalendarStart->format('d/m') }} al {{ $bookingCalendarEnd->format('d/m/Y') }}
                </span>
            </div>

            <dl class="wms-command-kpis" aria-label="Resumen del panel de control">
                <div>
                    <dt>Rol</dt>
                    <dd>{{ $currentRoleName }}</dd>
                    <span>Perfil activo</span>
                </div>
                <div class="{{ $dashboardPendingTotal > 0 ? 'has-alert' : '' }}">
                    <dt>Pendientes</dt>
                    <dd>{{ $dashboardPendingTotal }}</dd>
                    <span>{{ $dashboardPendingTotal === 1 ? 'Aviso activo' : 'Avisos activos' }}</span>
                </div>
                <div>
                    <dt>Agenda semana</dt>
                    <dd>{{ $dashboardAgendaTotal }}</dd>
                    <span>{{ $dashboardAgendaTotal === 1 ? 'Actividad' : 'Actividades' }}</span>
                </div>
                <div>
                    <dt>Módulos activos</dt>
                    <dd>{{ $visibleModuleCount }}</dd>
                    <span>Según permisos</span>
                </div>
            </dl>
        </section>

        <section class="surface-card wms-quick-panel" aria-labelledby="quick-actions-title">
            <div class="wms-command-section-heading">
                <div>
                    <span class="wms-command-eyebrow">Operación directa</span>
                    <h2 id="quick-actions-title">Acciones rápidas</h2>
                </div>
                <span>{{ $dashboardQuickActions->count() }} accesos</span>
            </div>

            <div class="wms-quick-actions">
                @foreach ($dashboardQuickActions as $action)
                    <a href="{{ route($action['route']) }}" class="wms-quick-action">
                        <span class="wms-quick-action-icon" aria-hidden="true">
                            <x-module-icon :name="$action['icon']" />
                        </span>
                        <span class="wms-quick-action-copy">
                            <small>{{ $action['hint'] }}</small>
                            <strong>{{ $action['label'] }}</strong>
                        </span>
                        <span class="wms-command-arrow" aria-hidden="true">→</span>
                    </a>
                @endforeach
            </div>
        </section>

        <div class="wms-command-layout">
            <section class="wms-command-modules" aria-labelledby="module-areas-title">
                <div class="wms-command-block-title">
                    <div>
                        <span class="wms-command-eyebrow">Mapa operativo</span>
                        <h2 id="module-areas-title">Módulos por áreas</h2>
                    </div>
                    <p>Accesos disponibles para tu rol, organizados por función.</p>
                </div>

                <div class="wms-module-groups">
                    @foreach ($navigationSections as $section)
                        @php
                            $sectionPendingTotal = collect($section['children'])
                                ->sum(fn (array $child) => (int) ($child['pending_count'] ?? 0));
                        @endphp

                        <article class="surface-card wms-module-group wms-module-group--{{ $section['key'] }}">
                            <header class="wms-module-group-head">
                                <div>
                                    <span class="wms-command-eyebrow">Área operativa</span>
                                    <h3>{{ $section['title'] }}</h3>
                                    @if (! empty($section['summary']))
                                        <p>{{ \Illuminate\Support\Str::limit($section['summary'], 88) }}</p>
                                    @endif
                                </div>
                                <div class="wms-module-group-counters">
                                    @if ($sectionPendingTotal > 0)
                                        <span class="wms-pending-count">{{ $sectionPendingTotal }} pendiente{{ $sectionPendingTotal === 1 ? '' : 's' }}</span>
                                    @endif
                                    <span class="wms-module-count">{{ count($section['children']) }}</span>
                                </div>
                            </header>

                            <div class="ops-index-list wms-module-list">
                                @foreach ($section['children'] as $child)
                                    @php
                                        $pendingCount = (int) ($child['pending_count'] ?? 0);
                                        $isActive = request()->routeIs(...($child['active_patterns'] ?? [$child['route']]));
                                    @endphp

                                    <a href="{{ route($child['display_route'] ?? $child['route']) }}" class="ops-index-link{{ $isActive ? ' is-active' : '' }}{{ $pendingCount > 0 ? ' has-pending' : '' }} wms-command-module">
                                        <span class="module-link-body">
                                            <span class="module-link-icon" aria-hidden="true">
                                                <x-module-icon :name="$child['display_icon']" />
                                            </span>
                                            <span class="module-link-copy">
                                                <strong>{{ $child['display_title'] ?? $child['title'] }}</strong>
                                                @if (! empty($child['summary']))
                                                    <span title="{{ $child['summary'] }}">{{ \Illuminate\Support\Str::limit($child['summary'], 86) }}</span>
                                                @endif
                                            </span>
                                        </span>
                                        <span class="wms-command-module-meta">
                                            @if ($pendingCount > 0)
                                                <span class="dashboard-module-pending badge-compact">{{ $pendingCount }}</span>
                                            @elseif (($child['status'] ?? 'ready') !== 'ready')
                                                <span class="ops-status badge-compact ops-status--placeholder">{{ $child['status_label'] }}</span>
                                            @endif
                                            <span class="wms-command-arrow" aria-hidden="true">→</span>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <aside class="wms-command-rail" aria-label="Atención operativa y agenda semanal">
                <section class="surface-card wms-attention-card" aria-labelledby="attention-title">
                    <div class="wms-command-section-heading">
                        <div>
                            <span class="wms-command-eyebrow">Monitor de avisos</span>
                            <h2 id="attention-title">Atención operativa</h2>
                        </div>
                        <span class="wms-attention-total{{ $dashboardPendingTotal > 0 ? ' has-pending' : '' }}">{{ $dashboardPendingTotal }}</span>
                    </div>

                    @if ($dashboardPendingModules->isEmpty())
                        <div class="wms-attention-empty">
                            <span aria-hidden="true">✓</span>
                            <div>
                                <strong>Sin pendientes</strong>
                                <p>No hay avisos operativos sin leer.</p>
                            </div>
                        </div>
                    @else
                        <div class="wms-attention-list">
                            @foreach ($dashboardPendingModules as $module)
                                @php
                                    $pendingCount = (int) $module['pending_count'];
                                @endphp
                                <a href="{{ route($module['display_route'] ?? $module['route']) }}">
                                    <span class="wms-attention-icon" aria-hidden="true">
                                        <x-module-icon :name="$module['display_icon']" />
                                    </span>
                                    <span>
                                        <strong>{{ $module['display_title'] ?? $module['title'] }}</strong>
                                        <small>{{ $pendingCount }} pendiente{{ $pendingCount === 1 ? '' : 's' }} por revisar</small>
                                    </span>
                                    <b>{{ $pendingCount }}</b>
                                </a>
                            @endforeach
                        </div>
                    @endif

                    <a href="{{ route('notifications.index') }}" class="wms-attention-link">
                        Abrir centro de notificaciones
                        <span aria-hidden="true">→</span>
                    </a>
                </section>

                <section class="surface-card dashboard-calendar-card wms-command-agenda" aria-labelledby="agenda-title">
                    <header class="wms-command-agenda-head">
                        <div>
                            <span class="wms-command-eyebrow">Planificación semanal</span>
                            <h2 id="agenda-title">{{ $isClient ? 'Agenda de BOOKING' : 'Agenda operativa WMS' }}</h2>
                            <p>{{ $bookingCalendarStart->format('d/m') }} — {{ $bookingCalendarEnd->format('d/m/Y') }}</p>
                        </div>
                        <a href="{{ route('bookings.calendar') }}" class="button-secondary compact-button btn-table dashboard-notifications-link">Abrir agenda</a>
                    </header>

                    <div class="wms-agenda-summary" aria-label="Resumen de agenda">
                        <span><strong>{{ $dashboardAgendaBookings }}</strong> booking{{ $dashboardAgendaBookings === 1 ? '' : 's' }}</span>
                        @if ($showGoogleCalendarLayer)
                            <span><strong>{{ $dashboardGoogleEvents }}</strong> Google</span>
                        @endif
                    </div>

                    <div class="dashboard-booking-calendar-grid wms-agenda-days">
                        @foreach ($bookingCalendarDays as $day)
                            @php
                                $dayBookingCount = $day['bookings']->count();
                                $dayGoogleCount = $showGoogleCalendarLayer ? $day['google_events']->count() : 0;
                                $dayHasActivity = $dayBookingCount > 0 || $dayGoogleCount > 0;
                            @endphp

                            <article class="dashboard-booking-day wms-agenda-day{{ $dayHasActivity ? ' has-activity' : '' }}">
                                <header class="wms-agenda-day-label">
                                    <strong>{{ \Illuminate\Support\Str::ucfirst($day['date']->locale('es')->isoFormat('dddd')) }}</strong>
                                    <span>{{ $day['date']->format('d/m') }}</span>
                                    @if ($dayHasActivity)
                                        <b>{{ $dayBookingCount + $dayGoogleCount }}</b>
                                    @endif
                                </header>

                                <div class="wms-agenda-day-content">
                                    @if (! $dayHasActivity)
                                        <span class="dashboard-booking-day-empty">Sin actividad</span>
                                    @else
                                        <div class="dashboard-booking-day-list wms-agenda-items">
                                            @foreach ($day['bookings'] as $booking)
                                                <a href="{{ route('bookings.show', $booking) }}" class="dashboard-booking-chip dashboard-booking-chip--{{ $booking->status }} wms-agenda-booking">
                                                    <span class="wms-agenda-item-top">
                                                        <strong>{{ $booking->referenceCode() }}</strong>
                                                        <span class="wms-status-chip wms-status-chip--{{ $booking->status }}">{{ $booking->statusLabel() }}</span>
                                                    </span>
                                                    <span>{{ $booking->typeLabel() }} · {{ $booking->client?->name ?? 'Sin cliente' }}</span>
                                                    <small>
                                                        @if ($booking->scheduled_time_from || $booking->scheduled_time_to)
                                                            {{ substr((string) ($booking->scheduled_time_from ?? $booking->scheduled_time_to), 0, 5) }} ·
                                                        @endif
                                                        {{ number_format($booking->pallets_expected ?? 0, 0, ',', '.') }} pallets
                                                    </small>
                                                </a>
                                            @endforeach

                                            @if ($showGoogleCalendarLayer)
                                                @foreach ($day['google_events'] as $googleEvent)
                                                    <article class="dashboard-google-event-chip wms-agenda-google">
                                                        <span class="wms-agenda-item-top">
                                                            <strong>{{ $googleEvent['title'] }}</strong>
                                                            <span class="wms-calendar-source wms-calendar-source-google">Google</span>
                                                        </span>
                                                        <span>
                                                            {{ $googleEvent['all_day'] ? 'Todo el día' : $googleEvent['starts_at']->format('H:i') . ' - ' . $googleEvent['ends_at']->format('H:i') }}
                                                        </span>
                                                        @if ($googleEvent['location'])
                                                            <small>{{ $googleEvent['location'] }}</small>
                                                        @endif
                                                    </article>
                                                @endforeach
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            </aside>
        </div>
    </div>
@endsection
