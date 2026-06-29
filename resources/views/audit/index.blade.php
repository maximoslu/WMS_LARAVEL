@extends('layouts.dashboard')

@section('title', 'Auditoría y trazabilidad | MAXIMO WMS')
@section('topbar_title', 'Auditoría y trazabilidad')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <span>Sistema</span>
        <span>/</span>
        <span>Auditoría y trazabilidad</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card audit-hero-card">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">Auditoría y trazabilidad</h2>
            <span class="ops-page-meta">Herramientas de control, trazabilidad y mantenimiento.</span>
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

    <section class="audit-grid">
        <article class="surface-card compact-card audit-card">
            <div class="ops-index-heading">
                <strong>Limpieza de registros</strong>
                <span class="ops-page-meta">Previsualizacion obligatoria antes de borrar</span>
            </div>

            <p class="audit-card-copy">
                Revisa alcance, filtros y advertencias antes de borrar. Esta pantalla esta pensada para validar impacto y dejar una decision clara antes de escalar a SUPERADMIN.
            </p>

            <form method="POST" action="{{ route('audit.cleanup.preview') }}" class="item-form">
                @csrf

                <div class="form-grid">
                    <label class="auth-field">
                        <span>Tipo de registro</span>
                        <select name="cleanup_type" class="auth-input" required>
                            @foreach ($cleanupTypes as $key => $label)
                                <option value="{{ $key }}" @selected($filters['cleanup_type'] === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="auth-field">
                        <span>Fecha desde</span>
                        <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="auth-input" required>
                    </label>

                    <label class="auth-field">
                        <span>Fecha hasta</span>
                        <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="auth-input" required>
                    </label>

                    <label class="auth-field">
                        <span>Cliente</span>
                        <select name="client_id" class="auth-input">
                            <option value="">Todos</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}" @selected((string) $filters['client_id'] === (string) $client->id)>{{ $client->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="auth-field">
                        <span>Estado opcional</span>
                        <select name="status" class="auth-input">
                            <option value="">Todos</option>
                            @foreach ($importStatuses as $status => $label)
                                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="item-form-actions action-buttons">
                    <button type="submit" class="button-primary compact-button btn-compact">Previsualizar limpieza</button>
                </div>
            </form>
        </article>

        <aside class="surface-card compact-card audit-card audit-card--side">
            <strong>Alcance seguro en esta fase</strong>
            <ul class="audit-note-list">
                <li>Notificaciones antiguas.</li>
                <li>Importaciones fallidas o solo previsualizadas.</li>
                <li>Jobs fallidos historicos.</li>
            </ul>
            <p class="helper-text">TODO: definir politica definitiva de retencion, borrado de historicos cerrados y trazabilidad ampliada.</p>
        </aside>
    </section>

    @if ($previewResult)
        <section class="surface-card compact-card audit-preview-card">
            <div class="ops-index-heading">
                <strong>Resultado de la previsualizacion</strong>
                <span class="ops-status badge-compact">{{ number_format($previewResult['count'], 0, ',', '.') }} registros</span>
            </div>

            <p class="audit-card-copy">{{ $previewResult['description'] }}</p>

            <div class="audit-preview-warnings">
                @foreach ($previewResult['warnings'] as $warning)
                    <article class="audit-warning-chip">{{ $warning }}</article>
                @endforeach
            </div>

            @if ($canExecuteCleanup)
                <form method="POST" action="{{ route('audit.cleanup.execute') }}" class="item-form audit-execute-form">
                    @csrf
                    @foreach ($previewResult['filters'] as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach

                    <label class="auth-field">
                        <span>Confirmacion obligatoria</span>
                        <input
                            type="text"
                            name="confirmation_text"
                            class="auth-input"
                            placeholder="Escribe CONFIRMAR LIMPIEZA"
                            required
                        >
                    </label>

                    <div class="item-form-actions action-buttons">
                        <button type="submit" class="button-secondary compact-button btn-compact">Ejecutar limpieza</button>
                    </div>
                </form>
            @else
                <p class="helper-text">Solo SUPERADMIN puede ejecutar la limpieza real. Administracion puede revisar el impacto antes de escalar.</p>
            @endif
        </section>
    @endif
@endsection
