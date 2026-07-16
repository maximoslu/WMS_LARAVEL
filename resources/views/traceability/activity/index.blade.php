@extends('layouts.dashboard')

@section('title', 'Actividad | Trazabilidad')
@section('topbar_title', 'Actividad de usuarios')

@section('content')
    <x-breadcrumbs :items="[['label' => 'Trazabilidad', 'href' => route('traceability.index'), 'icon' => 'audit'], ['label' => 'Actividad']]" />
    @include('traceability._nav')

    <section class="surface-card ops-page-header page-header-compact compact-card"><div class="app-copy"><h1 class="ops-page-title page-title-compact">Actividad de usuarios</h1><p>Tiempo activo estimado a partir de sesiones y heartbeats visibles. No representa presencia exacta ni registra contenido escrito.</p></div></section>
    <section class="surface-card item-filter-card compact-card"><form method="GET" class="stock-filters compact-filters filters-compact traceability-filter-grid">
        <label class="auth-field"><span>Usuario</span><select name="user_id" class="auth-input"><option value="">Todos</option>@foreach ($users as $user)<option value="{{ $user->id }}" @selected((int) ($filters['user_id'] ?? 0) === $user->id)>{{ $user->name }} · {{ $user->role?->name }}</option>@endforeach</select></label>
        <label class="auth-field"><span>Cliente</span><select name="client_id" class="auth-input"><option value="">Todos</option>@foreach ($clients as $client)<option value="{{ $client->id }}" @selected((int) ($filters['client_id'] ?? 0) === $client->id)>{{ $client->name }}</option>@endforeach</select></label>
        <label class="auth-field"><span>Rol</span><select name="role" class="auth-input"><option value="">Todos</option>@foreach ($roles as $role)<option value="{{ $role['slug'] }}" @selected(($filters['role'] ?? '') === $role['slug'])>{{ $role['name'] }}</option>@endforeach</select></label>
        <label class="auth-field"><span>Desde</span><input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="auth-input"></label>
        <label class="auth-field"><span>Hasta</span><input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="auth-input"></label>
        <label class="auth-field"><span>Seccion</span><input type="text" name="section" value="{{ $filters['section'] ?? '' }}" class="auth-input" maxlength="100"></label>
        <div class="stock-filter-actions action-buttons"><button class="button-primary compact-button btn-compact">Filtrar</button><a href="{{ route('traceability.activity.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a></div>
    </form></section>

    @if ($selectedUser)
        <div class="traceability-content-grid">
            <section class="surface-card stock-table-shell compact-card"><div class="item-form-header"><div class="app-copy"><h2 class="ops-page-title page-title-compact">Secciones de {{ $selectedUser->name }}</h2></div></div><div class="data-table-wrap"><table class="data-table table-compact"><thead><tr><th>Seccion</th><th class="numeric-cell">Visitas</th><th class="numeric-cell">Tiempo activo estimado</th><th>Ultima actividad</th></tr></thead><tbody>@forelse ($metrics as $metric)<tr><td><strong>{{ $metric->section }}</strong></td><td class="numeric-cell">{{ number_format($metric->visits, 0, ',', '.') }}</td><td class="numeric-cell">{{ gmdate('H:i:s', (int) $metric->active_seconds) }}</td><td>{{ $metric->last_seen_at?->format('d/m/Y H:i') }}</td></tr>@empty<tr><td colspan="4">Sin metricas en el periodo.</td></tr>@endforelse</tbody></table></div></section>
            <section class="surface-card compact-card"><div class="item-form-header"><div class="app-copy"><h2 class="ops-page-title page-title-compact">Acciones empresariales</h2></div></div><div class="traceability-event-list">@forelse ($actions as $action)<a href="{{ route('traceability.audit.index', ['correlation_id' => $action->correlation_id]) }}"><strong>{{ $action->description }}</strong><span>{{ $action->occurred_at?->format('d/m/Y H:i') }} · {{ $action->module }}</span></a>@empty<p class="helper-text">Sin acciones auditadas en el periodo.</p>@endforelse</div></section>
        </div>
    @endif

    <section class="surface-card stock-table-shell compact-card"><div class="data-table-wrap"><table class="data-table table-compact"><thead><tr><th>Usuario</th><th>Rol / cliente</th><th>Inicio</th><th>Ultima actividad</th><th>Fin</th><th class="numeric-cell">Tiempo activo estimado</th></tr></thead><tbody>@forelse ($sessions as $session)<tr><td><strong>{{ $session->user_name }}</strong></td><td>{{ $session->user_role }}<small class="table-subline">Cliente #{{ $session->client_id ?: '—' }}</small></td><td>{{ $session->started_at?->format('d/m/Y H:i') }}</td><td>{{ $session->last_seen_at?->format('d/m/Y H:i') }}</td><td>{{ $session->ended_at?->format('d/m/Y H:i') ?: 'Activa/inactiva por timeout' }}</td><td class="numeric-cell">{{ gmdate('H:i:s', (int) $session->active_seconds) }}</td></tr>@empty<tr><td colspan="6">Sin sesiones en el periodo.</td></tr>@endforelse</tbody></table></div></section>
    @if ($sessions->hasPages())<div class="pagination-card surface-card compact-card">{{ $sessions->links() }}</div>@endif
@endsection
