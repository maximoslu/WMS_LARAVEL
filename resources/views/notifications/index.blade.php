@extends('layouts.dashboard')

@section('title', 'Notificaciones | MAXIMO WMS')
@section('topbar_title', 'Notificaciones')

@section('content')
    @php
        $breadcrumbs = [


        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => 'Notificaciones'],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Notificaciones</h2>
            <span class="ops-page-meta">{{ $notifications->total() }} registros</span>
            @if (auth()->user()?->isSuperAdmin())
                <span class="ops-page-meta">Como superadmin puedes marcar como leidas las notificaciones de todos los usuarios.</span>
            @endif
        </div>

        @if (auth()->user()?->isSuperAdmin())
            <div class="ops-page-actions page-actions-compact action-buttons notification-admin-actions">
                <form
                    method="POST"
                    action="{{ route('notifications.read-all') }}"
                    onsubmit="return confirm('Vas a marcar como leidas TODAS las notificaciones de TODOS los usuarios. Esta accion no borra nada. Continuar?');"
                >
                    @csrf
                    <button
                        type="submit"
                        class="button-secondary compact-button btn-compact"
                        aria-label="Marcar todas las notificaciones de todos los usuarios como leidas"
                    >
                        Marcar todas como leidas
                    </button>
                </form>

                <form
                    method="POST"
                    action="{{ route('notifications.destroy-unread') }}"
                    onsubmit="return confirm('Esto eliminara todas las notificaciones no leidas de todos los usuarios. Continuar?');"
                >
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        class="button-secondary compact-button btn-compact notification-danger-btn"
                        aria-label="Eliminar todas las notificaciones no leidas de todos los usuarios"
                    >
                        Eliminar no leidas
                    </button>
                </form>

                <form
                    method="POST"
                    action="{{ route('notifications.destroy-all') }}"
                    onsubmit="return confirm('Esto eliminara TODAS las notificaciones de TODOS los usuarios. Esta accion no se puede deshacer. Continuar?');"
                >
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        class="button-secondary compact-button btn-compact notification-danger-btn"
                        aria-label="Eliminar todas las notificaciones de todos los usuarios"
                    >
                        Eliminar todas
                    </button>
                </form>
            </div>
        @endif
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($notifications->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin avisos</span>
            <h3>No hay notificaciones recientes.</h3>
            <p>Cuando el sistema registre avisos operativos apareceran aqui.</p>
        </article>
    @else
        <section class="surface-card compact-card notification-summary-card">
            <strong>Mostrando {{ $notifications->firstItem() }}-{{ $notifications->lastItem() }} de {{ $notifications->total() }} notificaciones</strong>
        </section>

        <section class="surface-card compact-card notification-inbox" aria-label="Bandeja de notificaciones">
            @foreach ($notifications as $notification)
                @include('notifications._card', ['notification' => $notification])
            @endforeach
        </section>

        @if ($notifications->hasPages())
            <div class="pagination-card surface-card compact-card">
                {{ $notifications->links() }}
            </div>
        @endif
    @endif
@endsection





