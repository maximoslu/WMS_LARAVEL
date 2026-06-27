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
