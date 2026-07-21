@extends('layouts.dashboard')

@section('title', 'Solicitud | MAXIMO WMS')
@section('topbar_title', 'Solicitud')

@section('content')
    @php
        $breadcrumbs = [


        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => $isClient ? 'Solicitudes' : 'Bookings', 'href' => route('bookings.index')],
        ['label' => $booking->referenceCode()],
        ];
        $isClientRequestedBooking = $booking->wasRequestedByClient();
        $statusActionTitle = match (true) {
            $isClientRequestedBooking && $booking->status === \App\Models\Booking::STATUS_REQUESTED => 'Solicitud pendiente',
            $isClientRequestedBooking && $booking->status === \App\Models\Booking::STATUS_APPROVED => 'Booking aprobado',
            $isClientRequestedBooking && $booking->status === \App\Models\Booking::STATUS_REJECTED => 'Solicitud rechazada',
            $isClientRequestedBooking && $booking->status === \App\Models\Booking::STATUS_CANCELLED => 'Solicitud cancelada',
            default => 'Gestion interna',
        };
        $statusActionCopy = match (true) {
            $isClientRequestedBooking && $booking->status === \App\Models\Booking::STATUS_REQUESTED => 'Aprueba el booking para incorporarlo a la agenda o rechazalo.',
            $isClientRequestedBooking && $booking->status === \App\Models\Booking::STATUS_APPROVED => 'Ya esta aprobado y aparece en la agenda operativa.',
            $isClientRequestedBooking && $booking->status === \App\Models\Booking::STATUS_REJECTED => 'No aparece como actividad operativa activa en agenda.',
            $isClientRequestedBooking && $booking->status === \App\Models\Booking::STATUS_CANCELLED => 'La solicitud queda fuera de la agenda operativa activa.',
            default => 'Acciones contextuales segun el estado actual del booking.',
        };
        $statusActionLabels = [
            \App\Models\Booking::STATUS_APPROVED => 'Aprobar booking',
            \App\Models\Booking::STATUS_REJECTED => 'Rechazar',
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <section class="surface-card ops-page-header page-header-compact compact-card wms-page-hero">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Solicitud {{ $booking->referenceCode() }}</h2>
            <span class="ops-page-meta">{{ $booking->client?->name ?? 'Sin cliente' }} - {{ $booking->scheduledWindowLabel() }}</span>
        </div>
        <div class="ops-page-actions page-actions-compact action-buttons">
            @if ($canEdit)
                <a href="{{ route('bookings.edit', $booking) }}" class="button-secondary compact-button btn-compact">Editar</a>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (session('warning'))
        <div class="alert alert-error">{{ session('warning') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    <section class="daily-ops-summary daily-ops-summary--metrics">
        <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
            <strong>Estado</strong>
            <span>{{ $booking->statusLabel() }}</span>
            <small>{{ $booking->typeLabel() }}</small>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
            <strong>Fecha / hora</strong>
            <span>{{ $booking->scheduledWindowLabel() }}</span>
            <small>Planificacion operativa</small>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
            <strong>Pallets previstos</strong>
            <span>{{ number_format($booking->pallets_expected ?? 0, 0, ',', '.') }}</span>
            <small>Carga operativa declarada</small>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
            <strong>Transportista</strong>
            <span>{{ $booking->carrier_name ?: 'Pendiente de asignar' }}</span>
            <small>{{ $booking->vehicle_plate ?: 'Pendiente de asignar' }}</small>
        </article>
    </section>

    <section class="daily-ops-grid">
        <article class="surface-card compact-card daily-ops-card">
            <div class="ops-index-heading">
                <strong>Datos de la solicitud</strong>
                <span class="ops-page-meta">Detalle operativo y seguimiento</span>
            </div>

            <div class="dashboard-notification-list">
                <article class="dashboard-notification-item">
                    <strong>Cliente</strong>
                    <p>{{ $booking->client?->name ?? 'Sin cliente' }}</p>
                </article>
                <article class="dashboard-notification-item">
                    <strong>Solicitante</strong>
                    <p>{{ $booking->requestedBy?->name ?? 'Sin usuario' }}</p>
                </article>
                <article class="dashboard-notification-item">
                    <strong>Contacto</strong>
                    <p>{{ $booking->contact_name ?: 'Sin contacto' }}{{ $booking->contact_phone ? ' - '.$booking->contact_phone : '' }}</p>
                </article>
                @if (! $isClient || filled($booking->origin_destination))
                    <article class="dashboard-notification-item">
                        <strong>Origen / destino</strong>
                        <p>{{ $booking->origin_destination ?: 'Pendiente de definir' }}</p>
                    </article>
                @endif
                @if (! $isClient || filled($booking->document_reference))
                    <article class="dashboard-notification-item">
                        <strong>Referencia documental</strong>
                        <p>{{ $booking->document_reference ?: 'Pendiente de registrar' }}</p>
                    </article>
                @endif
                @if (! $isClient || filled($booking->driver_name))
                    <article class="dashboard-notification-item">
                        <strong>{{ $isClient ? 'Persona asignada al transporte' : 'Conductor' }}</strong>
                        <p>{{ $booking->driver_name ?: 'Pendiente de asignar' }}</p>
                    </article>
                @endif
                <article class="dashboard-notification-item">
                    <strong>Observaciones cliente</strong>
                    <p>{{ $booking->notes ?: 'Sin observaciones' }}</p>
                </article>
                @unless ($isClient)
                    <article class="dashboard-notification-item">
                        <strong>Notas internas</strong>
                        <p>{{ $booking->internal_notes ?: 'Sin notas internas' }}</p>
                    </article>
                    <article class="dashboard-notification-item">
                        <strong>Asignado a</strong>
                        <p>{{ $booking->assignedTo?->name ?? 'Sin asignar' }}</p>
                    </article>
                    <article class="dashboard-notification-item">
                        <strong>Google Calendar</strong>
                        <p>{{ $booking->googleCalendarSyncLabel() }}</p>
                        @if ($booking->google_calendar_synced_at)
                            <p>Ultima sincronizacion: {{ $booking->google_calendar_synced_at->format('d/m/Y H:i') }}</p>
                        @endif
                        @if ($booking->google_calendar_sync_error)
                            <p>Error: {{ $booking->google_calendar_sync_error }}</p>
                        @endif
                        @if ($booking->google_calendar_event_id)
                            <p>Evento: {{ $booking->google_calendar_event_id }}</p>
                        @endif
                    </article>
                @endunless
            </div>
        </article>

        <article class="surface-card compact-card daily-ops-card">
            <div class="ops-index-heading">
                <strong>{{ $statusActionTitle }}</strong>
                <span class="ops-page-meta">{{ $statusActionCopy }}</span>
            </div>

            @if ($availableStatuses === [])
                <div class="item-empty-state">{{ $isClientRequestedBooking ? $booking->statusLabel() : 'No hay acciones de estado disponibles para este usuario.' }}</div>
            @else
                <div class="inline-actions action-buttons" style="margin-bottom: 1rem;">
                    @foreach ($availableStatuses as $status)
                        <form method="POST" action="{{ route('bookings.update-status', $booking) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $status }}">
                            <button type="submit" class="button-primary compact-button btn-table">{{ $statusActionLabels[$status] ?? (\App\Models\Booking::statusOptions()[$status] ?? ucfirst($status)) }}</button>
                        </form>
                    @endforeach
                </div>
            @endif

            @unless ($isClient)
                <form method="POST" action="{{ route('bookings.update-status', $booking) }}" class="item-form">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="{{ $booking->status }}">
                    <label class="auth-field item-form-field--full">
                        <span>Actualizar notas internas</span>
                        <textarea name="internal_notes" rows="5" class="auth-input">{{ old('internal_notes', $booking->internal_notes) }}</textarea>
                    </label>
                    <div class="item-form-actions action-buttons">
                        <button type="submit" class="button-secondary compact-button btn-compact">Guardar notas internas</button>
                    </div>
                </form>

                <p class="ops-page-meta">Google Calendar se muestra en modo solo lectura. WMS no crea ni modifica eventos Google.</p>
            @endunless
        </article>
    </section>
@endsection




