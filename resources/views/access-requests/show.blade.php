@extends('layouts.dashboard')

@section('title', 'Detalle solicitud de acceso | MAXIMO WMS')
@section('topbar_title', 'Detalle solicitud de acceso')

@section('content')
    @php
        $breadcrumbs = [
            ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
            ['label' => 'Solicitudes de acceso', 'href' => route('access-requests.index')],
            ['label' => $accessRequest->email],
        ];
    @endphp
    <x-breadcrumbs :items="$breadcrumbs" />

    <div class="wms-list-page wms-admin-page wms-access-request-detail-page">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <section class="surface-card compact-card wms-list-header wms-admin-header wms-access-request-detail-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Revision de solicitud</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">{{ $accessRequest->name }}</h2>
                    <span class="status-badge {{ 'access-request-status access-request-status--'.$accessRequest->status }}">
                        {{ match($accessRequest->status) {
                            'pending' => 'Pendiente',
                            'approved' => 'Aprobada',
                            'rejected' => 'Rechazada',
                            default => ucfirst($accessRequest->status),
                        } }}
                    </span>
                </div>
                <p class="wms-list-subtitle">
                    {{ $accessRequest->email }} · {{ $accessRequest->company ?: 'Sin empresa' }}
                </p>
            </div>

            <div class="wms-admin-header-side">
                <dl class="wms-list-metrics wms-admin-metrics">
                    <div>
                        <dt>Pendientes</dt>
                        <dd>{{ $pendingCount }}</dd>
                    </div>
                    <div>
                        <dt>Cliente</dt>
                        <dd>{{ $accessRequest->client ? 'Si' : 'No' }}</dd>
                    </div>
                    <div>
                        <dt>Usuario</dt>
                        <dd>{{ $accessRequest->user ? 'Si' : 'No' }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="surface-card compact-card access-request-detail-card wms-access-request-panel">
            <div class="wms-table-toolbar">
                <div>
                    <strong>Datos de la solicitud</strong>
                    <span>Informacion recibida desde el formulario publico</span>
                </div>
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
            <section class="access-request-actions-grid wms-access-request-actions-grid">
                <article class="surface-card compact-card access-request-action-card wms-access-request-action-card">
                    <h3>Aprobar solicitud</h3>
                    <p>Define el rol final del acceso. Solo el rol Cliente necesita un cliente asignado; los roles internos quedan con alcance global.</p>

                    <form method="POST" action="{{ route('access-requests.approve', $accessRequest) }}" class="access-request-form-stack">
                        @csrf
                        @method('PATCH')

                        <label class="auth-field">
                            <span>Rol de acceso</span>
                            <select name="role_id" class="auth-input" required data-access-role-select>
                                @foreach ($roles as $role)
                                    <option
                                        value="{{ $role->id }}"
                                        data-role-slug="{{ $role->slug }}"
                                        @selected((string) old('role_id', \App\Models\Role::CLIENTE) === (string) $role->slug || (string) old('role_id') === (string) $role->id)
                                    >
                                        {{ $role->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('role_id')
                                <small class="helper-text helper-text--error">{{ $message }}</small>
                            @enderror
                        </label>

                        <label class="auth-field">
                            <span>Cliente</span>
                            <select name="client_id" class="auth-input" data-access-client-select>
                                <option value="">Seleccionar cliente</option>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>{{ $client->name }}</option>
                                @endforeach
                            </select>
                            <small class="helper-text access-request-scope-hint" data-access-client-help>
                                El rol Cliente requiere una asignacion a cliente. Los roles internos se guardan sin cliente.
                            </small>
                            @error('client_id')
                                <small class="helper-text helper-text--error">{{ $message }}</small>
                            @enderror
                        </label>

                        <div class="access-request-approval-note">
                            <span data-access-role-summary>Rol inicial recomendado: Cliente</span>
                            <span>Estado del usuario: Activo</span>
                        </div>

                        <button type="submit" class="button-primary compact-button btn-compact">Aprobar</button>
                    </form>
                </article>

                <article class="surface-card compact-card access-request-action-card wms-access-request-action-card wms-access-request-action-card--danger">
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
            <section class="surface-card compact-card access-request-resolution-card wms-access-request-panel">
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
    </div>

    @if ($accessRequest->status === \App\Models\AccessRequest::STATUS_PENDING)
        <script>
            (() => {
                const roleSelect = document.querySelector('[data-access-role-select]');
                const clientSelect = document.querySelector('[data-access-client-select]');
                const clientHelp = document.querySelector('[data-access-client-help]');
                const roleSummary = document.querySelector('[data-access-role-summary]');

                if (!roleSelect || !clientSelect || !clientHelp || !roleSummary) {
                    return;
                }

                const syncApprovalScope = () => {
                    const selectedOption = roleSelect.options[roleSelect.selectedIndex];
                    const selectedRole = selectedOption?.dataset.roleSlug;
                    const selectedRoleName = selectedOption?.textContent?.trim() || 'Cliente';
                    const isClientRole = selectedRole === '{{ \App\Models\Role::CLIENTE }}';

                    clientSelect.required = isClientRole;
                    clientSelect.disabled = !isClientRole;

                    if (!isClientRole) {
                        clientSelect.value = ';
                    }

                    clientHelp.textContent = isClientRole
                        ? 'El rol Cliente requiere una asignacion a cliente.'
                        : 'El rol ' + selectedRoleName + ' tendra acceso interno y se guardara sin cliente asignado.';
                    roleSummary.textContent = 'Rol a aprobar: ' + selectedRoleName;
                };

                syncApprovalScope();
                roleSelect.addEventListener('change', syncApprovalScope);
            })();
        </script>
    @endif
@endsection





