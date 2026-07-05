@php
    /** @var \Illuminate\Notifications\DatabaseNotification $notification */
    $appearance = \App\Support\Notifications\NotificationPresentation::for($notification);
@endphp

<article class="surface-card compact-card notification-card notification-card--{{ $appearance['key'] }}{{ $notification->read_at === null ? ' is-unread' : '' }}">
    <div class="notification-card-head">
        <div class="notification-card-copy">
            <div class="notification-card-badges">
                <span class="notification-kind-badge notification-kind-badge--{{ $appearance['key'] }}">
                    {{ $appearance['label'] }}
                </span>
                <span class="ops-status badge-compact notification-state-badge">
                    {{ $notification->read_at === null ? 'Pendiente' : 'Leida' }}
                </span>
            </div>
            <strong>{{ $notification->data['title'] ?? 'Notificacion' }}</strong>
            <p>{{ $notification->data['body'] ?? 'Sin detalle adicional.' }}</p>
        </div>
    </div>

    <div class="notification-card-meta">
        <span class="notification-card-date">{{ $notification->created_at?->format('d/m/Y H:i') }}</span>
        <div class="inline-actions action-buttons notification-card-actions">
            @if (! empty($notification->data['url']))
                <a href="{{ $notification->data['url'] }}" class="button-secondary compact-button btn-table">Abrir</a>
            @endif

            @if ($notification->read_at === null)
                <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="button-secondary compact-button btn-table">Marcar leida</button>
                </form>
            @endif
        </div>
    </div>
</article>
