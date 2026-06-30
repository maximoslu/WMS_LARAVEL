@extends('layouts.dashboard')

@section('title', 'Detalle booking | MAXIMO WMS')
@section('topbar_title', 'Detalle booking')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <a href="{{ route('bookings.index') }}">Bookings</a>
        <span>/</span>
        <span>{{ $booking->referenceCode() }}</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">{{ $booking->referenceCode() }}</h2>
            <span class="ops-page-meta">{{ $booking->client?->name ?? 'Sin cliente' }} · {{ $booking->scheduledWindowLabel() }}</span>
        </div>
        <div class="ops-page-actions page-actions-compact action-buttons">
            @if ($canEdit)
                <a href="{{ route('bookings.edit', $booking) }}" class="button-secondary compact-button btn-compact">Editar booking</a>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
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
            <small>Planificación operativa</small>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
            <strong>Pallets previstos</strong>
            <span>{{ number_format($booking->pallets_expected ?? 0, 0, ',', '.') }}</span>
            <small>Carga operativa declarada</small>
        </article>
        <article class="surface-card stock-summary-card kpi-card kpi-compact daily-ops-metric-card">
            <strong>Transportista</strong>
            <span>{{ $booking->carrier_name ?: '-' }}</span>
            <small>{{ $booking->vehicle_plate ?: 'Sin matrícula' }}</small>
        </article>
    </section>

    <section class="daily-ops-grid">
        <article class="surface-card compact-card daily-ops-card">
            <div class="ops-index-heading">
                <strong>Datos del booking</strong>
                <span class="ops-page-meta">Detalle completo de la solicitud</span>
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
                    <p>{{ $booking->contact_name ?: 'Sin contacto' }}{{ $booking->contact_phone ? ' · '.$booking->contact_phone : '' }}</p>
                </article>
                <article class="dashboard-notification-item">
                    <strong>Conductor</strong>
                    <p>{{ $booking->driver_name ?: 'Sin conductor' }}</p>
                </article>
                <article class="dashboard-notification-item">
                    <strong>Origen / destino</strong>
                    <p>{{ $booking->origin_destination ?: 'Sin detalle' }}</p>
                </article>
                <article class="dashboard-notification-item">
                    <strong>Referencia documental</strong>
                    <p>{{ $booking->document_reference ?: 'Sin referencia' }}</p>
                </article>
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
                @endunless
            </div>
        </article>

        <article class="surface-card compact-card daily-ops-card">
            <div class="ops-index-heading">
                <strong>Acciones de estado</strong>
                <span class="ops-page-meta">Gestión operativa del booking</span>
            </div>

            @if ($availableStatuses === [])
                <div class="item-empty-state">No hay acciones de estado disponibles para este usuario.</div>
            @else
                <div class="inline-actions action-buttons" style="margin-bottom: 1rem;">
                    @foreach ($availableStatuses as $status)
                        <form method="POST" action="{{ route('bookings.update-status', $booking) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $status }}">
                            <button type="submit" class="button-primary compact-button btn-table">{{ \App\Models\Booking::statusOptions()[$status] ?? ucfirst($status) }}</button>
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
            @endunless

            <p class="helper-text">TODO: pendiente conectar booking con operaciones diarias y facturación sin booking.</p>
        </article>
    </section>
@endsection
