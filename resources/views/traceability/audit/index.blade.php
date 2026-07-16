@extends('layouts.dashboard')

@section('title', 'Auditoria empresarial | Trazabilidad')
@section('topbar_title', 'Auditoria empresarial')

@section('content')
    <x-breadcrumbs :items="[['label' => 'Trazabilidad', 'href' => route('traceability.index'), 'icon' => 'audit'], ['label' => 'Auditoria']]" />
    @include('traceability._nav')
    <section class="surface-card item-filter-card compact-card"><form method="GET" class="stock-filters compact-filters filters-compact traceability-filter-grid">
        <label class="auth-field"><span>Cliente</span><select name="client_id" class="auth-input"><option value="">Todos</option>@foreach ($clients as $client)<option value="{{ $client->id }}" @selected((int) ($filters['client_id'] ?? 0) === $client->id)>{{ $client->name }}</option>@endforeach</select></label>
        <label class="auth-field"><span>Usuario</span><select name="user_id" class="auth-input"><option value="">Todos</option>@foreach ($users as $user)<option value="{{ $user->id }}" @selected((int) ($filters['user_id'] ?? 0) === $user->id)>{{ $user->name }}</option>@endforeach</select></label>
        <label class="auth-field"><span>Modulo</span><select name="module" class="auth-input"><option value="">Todos</option>@foreach ($modules as $module)<option @selected(($filters['module'] ?? '') === $module)>{{ $module }}</option>@endforeach</select></label>
        <label class="auth-field"><span>Evento</span><select name="event" class="auth-input"><option value="">Todos</option>@foreach ($events as $event)<option @selected(($filters['event'] ?? '') === $event)>{{ $event }}</option>@endforeach</select></label>
        <label class="auth-field"><span>Correlacion</span><input type="text" name="correlation_id" value="{{ $filters['correlation_id'] ?? '' }}" class="auth-input"></label>
        <label class="auth-field"><span>Desde</span><input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="auth-input"></label>
        <label class="auth-field"><span>Hasta</span><input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="auth-input"></label>
        <div class="stock-filter-actions action-buttons"><button class="button-primary compact-button btn-compact">Filtrar</button><a href="{{ route('traceability.audit.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a></div>
    </form></section>
    <section class="surface-card stock-table-shell compact-card"><div class="data-table-wrap"><table class="data-table table-compact"><thead><tr><th>Fecha</th><th>Usuario</th><th>Modulo / evento</th><th>Descripcion</th><th>Entidad</th><th>Correlacion</th><th>Detalle</th></tr></thead><tbody>
        @forelse ($logs as $log)<tr><td>{{ $log->occurred_at?->format('d/m/Y H:i:s') }}</td><td><strong>{{ $log->user_name ?: 'Sistema' }}</strong><small class="table-subline">{{ $log->user_role ?: 'Proceso' }}</small></td><td>{{ $log->module }}<small class="table-subline">{{ $log->event }}</small></td><td>{{ $log->description }}</td><td>{{ class_basename($log->auditable_type ?: '') }} #{{ $log->auditable_id ?: '—' }}</td><td><span class="mono-link">{{ \Illuminate\Support\Str::limit($log->correlation_id, 8, '') }}</span></td><td><details><summary>Ver</summary><pre class="traceability-json">{{ json_encode(['antes' => $log->old_values, 'despues' => $log->new_values, 'metadata' => $log->metadata], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details></td></tr>@empty<tr><td colspan="7">No hay acciones con estos filtros.</td></tr>@endforelse
    </tbody></table></div></section>
    @if ($logs->hasPages())<div class="pagination-card surface-card compact-card">{{ $logs->links() }}</div>@endif
@endsection
