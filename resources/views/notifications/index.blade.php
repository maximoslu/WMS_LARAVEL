@extends('layouts.dashboard')

@section('title', 'Notificaciones | MAXIMO WMS')
@section('topbar_title', 'Notificaciones')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Notificaciones</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Notificaciones</h2>
            <span class="ops-page-meta">{{ $notifications->total() }} registros</span>
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($notifications->isEmpty())
        <article class="surface-card item-empty-state compact-card">
            <span class="status-chip small-badge badge-compact">Sin avisos</span>
            <h3>No hay notificaciones para mostrar</h3>
            <p>Cuando el sistema registre avisos operativos aparecerán aquí.</p>
        </article>
    @else
        <section class="notification-list">
            @foreach ($notifications as $notification)
                <article class="surface-card compact-card notification-card{{ $notification->read_at === null ? ' is-unread' : '' }}">
                    <div class="notification-card-head">
                        <div>
                            <strong>{{ $notification->data['title'] ?? 'Notificación' }}</strong>
                            <p>{{ $notification->data['body'] ?? 'Sin detalle adicional.' }}</p>
                        </div>
                        <span class="ops-status badge-compact">{{ $notification->read_at === null ? 'Pendiente' : 'Leída' }}</span>
                    </div>

                    <div class="notification-card-meta">
                        <span>{{ $notification->created_at?->format('d/m/Y H:i') }}</span>
                        <div class="inline-actions action-buttons">
                            @if (! empty($notification->data['url']))
                                <a href="{{ $notification->data['url'] }}" class="button-secondary compact-button btn-table">Abrir</a>
                            @endif

                            @if ($notification->read_at === null)
                                <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="button-secondary compact-button btn-table">Marcar leída</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </section>

        @if ($notifications->hasPages())
            <div class="pagination-card surface-card compact-card">
                {{ $notifications->links() }}
            </div>
        @endif
    @endif
@endsection
