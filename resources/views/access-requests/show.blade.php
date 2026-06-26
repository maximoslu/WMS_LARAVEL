@extends('layouts.dashboard')

@section('title', 'Detalle solicitud de acceso | MAXIMO WMS')
@section('topbar_title', 'Detalle solicitud de acceso')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <a href="{{ route('access-requests.index') }}">Solicitudes de acceso</a>
        <span>/</span>
        <span>{{ $accessRequest->email }}</span>
    </nav>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="surface-card compact-card access-request-detail-card">
        <div class="ops-page-headline">
            <div>
                <h2 class="ops-page-title page-title-compact">{{ $accessRequest->name }}</h2>
                <p class="access-request-detail-meta">{{ $accessRequest->email }} · {{ $accessRequest->company ?: 'Sin empresa' }}</p>
            </div>
            <span class="status-badge {{ 'access-request-status access-request-status--'.$accessRequest->status }}">
                {{ match($accessRequest->status) {
                    'pending' => 'Pendiente',
                    'approved' => 'Aprobada',
                    'rejected' => 'Rechazada',
                    default => ucfirst($accessRequest->status),
                } }}
            </span>
        </div>

        <dl class="access-request-detail-grid">
            <div>
                <dt>Fecha</dt>
                <dd>{{ $accessRequest->created_at?->format('Y-m-d H:i') }}</dd>
            </div>
            <div>
                <dt>Cliente asignado</dt>
                <dd>{{ $accessRequest->client?->name ?? 'Sin asignar' }}</dd>
            </div>
            <div>
                <dt>Usuario asociado</dt>
                <dd>{{ $accessRequest->user?->email ?? 'Aun no creado' }}</dd>
            </div>
            <div>
                <dt>Observaciones</dt>
                <dd>{{ $accessRequest->notes ?: 'Sin observaciones' }}</dd>
            </div>
        </dl>
    </section>

    @if ($accessRequest->status === \App\Models\AccessRequest::STATUS_PENDING)
        <section class="access-request-actions-grid">
            <article class="surface-card compact-card access-request-action-card">
                <h3>Aprobar solicitud</h3>
                <p>Se asignara rol Cliente y se activara el usuario para el cliente seleccionado.</p>

                <form method="POST" action="{{ route('access-requests.approve', $accessRequest) }}" class="access-request-form-stack">
                    @csrf
                    @method('PATCH')

                    <label class="auth-field">
                        <span>Cliente</span>
                        <select name="client_id" class="auth-input" required>
                            <option value="">Seleccionar cliente</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>{{ $client->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <div class="access-request-approval-note">
                        <span>Rol asignado por defecto: Cliente</span>
                        <span>Estado del usuario: Activo</span>
                    </div>

                    <button type="submit" class="button-primary compact-button btn-compact">Aprobar</button>
                </form>
            </article>

            <article class="surface-card compact-card access-request-action-card">
                <h3>Rechazar solicitud</h3>
                <p>Se registrara el motivo y se podra avisar al solicitante por correo.</p>

                <form method="POST" action="{{ route('access-requests.reject', $accessRequest) }}" class="access-request-form-stack">
                    @csrf
                    @method('PATCH')

                    <label class="auth-field">
                        <span>Motivo</span>
                        <textarea name="rejection_reason" rows="5" class="auth-input" placeholder="Explica el motivo del rechazo" required>{{ old('rejection_reason') }}</textarea>
                    </label>

                    <button type="submit" class="button-secondary compact-button btn-compact">Rechazar</button>
                </form>
            </article>
        </section>
    @else
        <section class="surface-card compact-card access-request-resolution-card">
            <h3>Resolucion</h3>

            @if ($accessRequest->status === \App\Models\AccessRequest::STATUS_APPROVED)
                <p>
                    Aprobada por <strong>{{ $accessRequest->approvedBy?->name ?? 'Sistema' }}</strong>
                    el {{ $accessRequest->approved_at?->format('Y-m-d H:i') }}.
                </p>
            @endif

            @if ($accessRequest->status === \App\Models\AccessRequest::STATUS_REJECTED)
                <p>
                    Rechazada por <strong>{{ $accessRequest->rejectedBy?->name ?? 'Sistema' }}</strong>
                    el {{ $accessRequest->rejected_at?->format('Y-m-d H:i') }}.
                </p>
                <p><strong>Motivo:</strong> {{ $accessRequest->rejection_reason ?: 'Sin detalle' }}</p>
            @endif
        </section>
    @endif
@endsection
