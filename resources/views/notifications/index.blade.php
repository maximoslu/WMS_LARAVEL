@extends('layouts.dashboard')

@section('title', 'Notificaciones | MAXIMO WMS')
@section('topbar_title', 'Notificaciones')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Notificaciones'],
        ];
        $notificationUser = auth()->user();
        $isSuperAdmin = $notificationUser?->isSuperAdmin();
        $visibleNotifications = $notifications->getCollection();
        $visibleUnreadCount = $visibleNotifications->whereNull('read_at')->count();
        $visibleReadCount = $notifications->count() - $visibleUnreadCount;
    @endphp

    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-notification-page">
        <section class="surface-card compact-card wms-list-header wms-notification-header">
            <div class="wms-list-headline">
                <span class="wms-list-kicker">Centro de avisos</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Notificaciones</h2>
                    <span class="wms-list-count">{{ $notifications->total() }} registros</span>
                </div>
                <p class="wms-list-subtitle">
                    Bandeja operativa para revisar avisos del WMS, priorizar no leidas y gestionar limpieza.
                    @if ($isSuperAdmin)
                        Como superadmin puedes marcar como leidas las notificaciones de todos los usuarios.
                    @endif
                </p>
            </div>

            <div class="wms-list-actions wms-notification-header-actions">
                <dl class="wms-list-metrics wms-notification-kpis" aria-label="Resumen visible de notificaciones">
                    <div>
                        <dt>Total</dt>
                        <dd>{{ $notifications->total() }}</dd>
                    </div>
                    <div>
                        <dt>No leidas</dt>
                        <dd>{{ $visibleUnreadCount }}</dd>
                    </div>
                    <div>
                        <dt>Leidas</dt>
                        <dd>{{ $visibleReadCount }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="surface-card compact-card wms-notification-toolbar" aria-label="Acciones de gestion de notificaciones">
            <div class="wms-notification-toolbar-copy">
                <strong>Gestion de bandeja</strong>
                <span>{{ $isSuperAdmin ? 'Acciones globales sobre todos los usuarios.' : 'Acciones sobre tus notificaciones.' }}</span>
            </div>

            <div class="ops-page-actions page-actions-compact action-buttons notification-admin-actions wms-notification-admin-actions">
                <form
                    method="POST"
                    action="{{ route('notifications.read-all') }}"
                    onsubmit="return confirm('{{ $isSuperAdmin ? 'Vas a marcar como leidas TODAS las notificaciones de TODOS los usuarios. Esta accion no borra nada. Continuar?' : 'Vas a marcar tus notificaciones como leidas. Continuar?' }}');"
                >
                    @csrf
                    <button
                        type="submit"
                        class="button-secondary compact-button btn-compact"
                        aria-label="{{ $isSuperAdmin ? 'Marcar todas las notificaciones de todos los usuarios como leidas' : 'Marcar mis notificaciones como leidas' }}"
                    >
                        {{ $isSuperAdmin ? 'Marcar todas como leidas' : 'Marcar mis notificaciones como leidas' }}
                    </button>
                </form>

                <form
                    method="POST"
                    action="{{ route('notifications.destroy-unread') }}"
                    onsubmit="return confirm('{{ $isSuperAdmin ? 'Esto eliminara todas las notificaciones no leidas de todos los usuarios. Continuar?' : 'Esto eliminara tus notificaciones no leidas. Continuar?' }}');"
                >
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        class="button-secondary compact-button btn-compact notification-danger-btn"
                        aria-label="{{ $isSuperAdmin ? 'Eliminar todas las notificaciones no leidas de todos los usuarios' : 'Eliminar mis notificaciones no leidas' }}"
                    >
                        {{ $isSuperAdmin ? 'Eliminar no leidas' : 'Eliminar mis no leidas' }}
                    </button>
                </form>

                <form
                    method="POST"
                    action="{{ route('notifications.destroy-all') }}"
                    onsubmit="return confirm('{{ $isSuperAdmin ? 'Esto eliminara TODAS las notificaciones de TODOS los usuarios. Esta accion no se puede deshacer. Continuar?' : 'Esto eliminara tus notificaciones. Continuar?' }}');"
                >
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        class="button-secondary compact-button btn-compact notification-danger-btn"
                        aria-label="{{ $isSuperAdmin ? 'Eliminar todas las notificaciones de todos los usuarios' : 'Eliminar mis notificaciones' }}"
                    >
                        {{ $isSuperAdmin ? 'Eliminar todas' : 'Eliminar mis notificaciones' }}
                    </button>
                </form>
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($notifications->isEmpty())
            <article class="surface-card compact-card wms-empty-state wms-notification-empty">
                <div>
                    <span class="wms-status-chip wms-status-chip--neutral">Sin avisos</span>
                    <h3>No hay notificaciones recientes.</h3>
                    <p>Cuando el sistema registre avisos operativos apareceran aqui.</p>
                </div>
            </article>
        @else
            <section class="surface-card compact-card notification-summary-card wms-notification-summary">
                <strong>Mostrando {{ $notifications->firstItem() }}-{{ $notifications->lastItem() }} de {{ $notifications->total() }} notificaciones</strong>
                <span>{{ $visibleUnreadCount }} no leida{{ $visibleUnreadCount === 1 ? '' : 's' }} en esta pagina</span>
            </section>

            <section class="surface-card compact-card notification-inbox wms-notification-inbox" aria-label="Bandeja de notificaciones">
                <div class="wms-notification-inbox-head" aria-hidden="true">
                    <span>Aviso</span>
                    <span>Fecha</span>
                    <span>Acciones</span>
                </div>

                <div class="wms-notification-list">
                    @foreach ($notifications as $notification)
                        @include('notifications._card', ['notification' => $notification])
                    @endforeach
                </div>
            </section>

            @if ($notifications->hasPages())
                <div class="pagination-card surface-card compact-card">
                    {{ $notifications->links() }}
                </div>
            @endif
        @endif
    </div>
@endsection
