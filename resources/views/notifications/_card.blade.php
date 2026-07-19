@php
    /** @var \Illuminate\Notifications\DatabaseNotification $notification */
    $appearance = \App\Support\Notifications\NotificationPresentation::for($notification);
    $isUnread = $notification->read_at === null;
@endphp

<article class="notification-card notification-card--{{ $appearance['key'] }}{{ $isUnread ? ' is-unread wms-notification-unread' : '' }} wms-notification-item">
    <span class="notification-card-state" aria-hidden="true"></span>

    <div class="notification-card-copy wms-notification-copy">
        <div class="notification-card-line wms-notification-line">
            <strong class="notification-card-title">{{ $notification->data['title'] ?? 'Notificacion' }}</strong>
            <span class="notification-card-badges">
                <span class="notification-kind-badge notification-kind-badge--{{ $appearance['key'] }}">
                    {{ $appearance['label'] }}
                </span>
                <span class="notification-state-badge wms-notification-state-badge">
                    {{ $isUnread ? 'Nueva' : 'Leida' }}
                </span>
            </span>
        </div>
        <p class="notification-card-body">{{ $notification->data['body'] ?? 'Sin detalle adicional.' }}</p>
    </div>

    <time class="notification-card-date wms-notification-date" datetime="{{ $notification->created_at?->toIso8601String() }}">
        {{ $notification->created_at?->format('d/m/Y H:i') }}
    </time>

    <div class="inline-actions action-buttons notification-card-actions wms-row-actions wms-notification-actions">
        @if (! empty($notification->data['url']))
            <a href="{{ $notification->data['url'] }}" class="button-secondary compact-button btn-table">Abrir</a>
        @endif

        @if ($isUnread)
            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="button-secondary compact-button btn-table">Marcar leida</button>
            </form>
        @endif
    </div>
</article>
