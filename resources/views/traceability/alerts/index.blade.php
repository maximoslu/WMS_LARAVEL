@extends('layouts.dashboard')

@section('title', 'Alertas de stock | Trazabilidad')
@section('topbar_title', 'Alertas de stock')

@section('content')
    @php
        $visibleEvents = $events->getCollection();
        $activeEvents = $visibleEvents->filter(fn ($event) => $event->resolved_at === null)->count();
        $criticalEvents = $visibleEvents->where('severity', 'critical')->count();
        $selectedClient = $clients->firstWhere('id', (int) ($filters['client_id'] ?? 0));
    @endphp

    <x-breadcrumbs :items="[['label' => 'Trazabilidad', 'href' => route('traceability.index'), 'icon' => 'audit'], ['label' => 'Alertas de stock']]" />
    @include('traceability._nav')

    <div class="wms-list-page wms-stock-admin-page wms-stock-alerts-page">
        <section class="surface-card compact-card wms-list-header wms-stock-admin-header">
            <div class="wms-list-heading">
                <span class="wms-list-kicker">Trazabilidad / Alertas de stock</span>
                <div class="wms-list-title-row">
                    <h2 class="ops-page-title page-title-compact">Alertas de stock</h2>
                    <span class="wms-list-count">{{ $events->total() }} eventos</span>
                </div>
                <p class="wms-list-subtitle">
                    Seguimiento de eventos generados por reglas de umbral, cobertura y agotamiento, con acciones de gestion por alerta.
                </p>
            </div>

            <div class="wms-stock-admin-header-side">
                <dl class="wms-list-metrics wms-stock-admin-metrics">
                    <div>
                        <dt>Activas</dt>
                        <dd>{{ $activeEvents }}</dd>
                    </div>
                    <div>
                        <dt>Criticas</dt>
                        <dd>{{ $criticalEvents }}</dd>
                    </div>
                    <div>
                        <dt>Reglas</dt>
                        <dd>{{ $rules->total() }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <section class="surface-card compact-card wms-filter-panel wms-stock-filter-panel">
            <form method="GET" class="stock-filters compact-filters filters-compact wms-filter-grid wms-stock-filter-grid wms-stock-filter-grid--alerts">
                <label class="auth-field">
                    <span>Cliente</span>
                    <select name="client_id" class="auth-input">
                        <option value="">Todos los clientes</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((int) ($filters['client_id'] ?? 0) === $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="auth-field">
                    <span>Estado</span>
                    <select name="status" class="auth-input">
                        <option value="active" @selected($filters['status'] === 'active')>Activas</option>
                        <option value="resolved" @selected($filters['status'] === 'resolved')>Resueltas</option>
                        <option value="all" @selected($filters['status'] === 'all')>Todas</option>
                    </select>
                </label>

                <div class="stock-filter-actions action-buttons wms-filter-actions">
                    <button class="button-primary compact-button btn-compact">Filtrar</button>
                    <a href="{{ route('traceability.alerts.index') }}" class="button-secondary compact-button btn-compact">Limpiar</a>
                </div>
            </form>

            <div class="wms-filter-summary" aria-label="Filtros aplicados">
                <span class="wms-filter-muted">Cliente: {{ $selectedClient?->name ?? 'Todos' }}</span>
                <span class="wms-filter-muted">Estado: {{ match($filters['status']) {
                    'resolved' => 'Resueltas',
                    'all' => 'Todas',
                    default => 'Activas',
                } }}</span>
                @if (! empty($filters['client_id']))
                    <span class="wms-filter-token">Reglas del cliente visibles</span>
                @endif
            </div>
        </section>

        @if ($canManage && ! empty($filters['client_id']))
            <details class="surface-card compact-card traceability-rule-editor wms-stock-rule-editor" @if ($errors->any()) open @endif>
                <summary>
                    <span>Nueva regla de alerta</span>
                    <small>Crear umbral para {{ $selectedClient?->name ?? 'cliente seleccionado' }}</small>
                </summary>
                <form method="POST" action="{{ route('traceability.alerts.rules.store') }}" class="traceability-rule-form wms-stock-rule-form">
                    @csrf
                    <input type="hidden" name="client_id" value="{{ $filters['client_id'] }}"><input type="hidden" name="active" value="1">
                    <label class="auth-field"><span>Articulo *</span><select name="item_id" class="auth-input" required><option value="">Seleccionar</option>@foreach ($items as $item)<option value="{{ $item->id }}">{{ $item->sku }} · {{ $item->description }}</option>@endforeach</select></label>
                    <label class="auth-field"><span>Unidades minimas</span><input type="number" name="minimum_units" min="0" class="auth-input"></label>
                    <label class="auth-field"><span>Pallets minimos</span><input type="number" name="minimum_pallets" min="0" class="auth-input"></label>
                    <label class="auth-field"><span>Cobertura minima (dias)</span><input type="number" name="minimum_coverage_days" min="0" max="3650" class="auth-input"></label>
                    <label class="auth-field"><span>Horizonte de agotamiento</span><input type="number" name="exhaustion_horizon_days" min="0" max="3650" class="auth-input"></label>
                    <label class="auth-field"><span>Stock de seguridad</span><input type="number" name="safety_stock_units" value="0" min="0" class="auth-input"></label>
                    <label class="auth-field"><span>Lead time (dias)</span><input type="number" name="lead_time_days" value="0" min="0" class="auth-input"></label>
                    <label class="auth-field"><span>Severidad base</span><select name="severity" class="auth-input"><option value="warning">Advertencia</option><option value="critical">Critica</option></select></label>
                    <label class="auth-field"><span>Cooldown (minutos)</span><input type="number" name="cooldown_minutes" value="1440" min="1" class="auth-input" required></label>
                    <label class="toggle-field"><input type="checkbox" name="include_blocked_stock" value="1"><span>Incluir bloqueado</span></label>
                    <label class="toggle-field"><input type="checkbox" name="include_obsolete_stock" value="1"><span>Incluir obsoleto</span></label>
                    <div class="item-form-actions action-buttons"><button class="button-primary compact-button btn-compact">Crear regla</button></div>
                </form>
            </details>
        @endif

        <section class="surface-card compact-card wms-table-panel wms-stock-table-panel">
            <div class="wms-table-toolbar">
                <div>
                    <strong>Eventos</strong>
                    <span>Solo se notifica al cruzar un umbral o empeorar tras el cooldown.</span>
                </div>
                <div class="wms-table-totals">
                    <span>{{ $events->count() }} visibles</span>
                </div>
            </div>

            <div class="data-table-wrap wms-table-wrap">
                <table class="data-table table-compact wms-stock-data-table wms-stock-alert-table">
                    <thead>
                        <tr>
                            <th>Estado</th>
                            <th>Cliente / articulo</th>
                            <th>Motivo</th>
                            <th>Stock / umbral</th>
                            <th>Cobertura</th>
                            <th>Notificacion</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($events as $event)
                            <tr>
                                <td>
                                    <span class="status-chip small-badge badge-compact trace-alert--{{ $event->severity }} wms-stock-alert-severity wms-stock-alert-severity--{{ $event->severity }}">{{ $event->status }}</span>
                                    <small class="table-subline">{{ $event->triggered_at?->format('d/m/Y H:i') }}</small>
                                </td>
                                <td>
                                    <strong>{{ $event->client?->name }}</strong>
                                    <small class="table-subline">{{ $event->item?->sku }} · {{ $event->item?->description }}</small>
                                </td>
                                <td>{{ $event->reason }}</td>
                                <td>{{ number_format($event->observed_units ?? 0, 0, ',', '.') }} / {{ $event->threshold_units !== null ? number_format($event->threshold_units, 0, ',', '.') : '-' }} uds</td>
                                <td>
                                    {{ $event->coverage_days !== null ? number_format($event->coverage_days, 1, ',', '.').' dias' : 'Sin datos' }}
                                    <small class="table-subline">Agotamiento: {{ $event->estimated_exhaustion_date?->format('d/m/Y') ?: 'No estimado' }}</small>
                                </td>
                                <td>
                                    {{ $event->notification_status }}
                                    <small class="table-subline">{{ implode(', ', $event->recipients ?? []) ?: 'Sin destinatarios' }}</small>
                                </td>
                                <td>
                                    @if ($canManage && ! $event->resolved_at)
                                        <div class="inline-actions action-buttons wms-row-actions">
                                            <form method="POST" action="{{ route('traceability.alerts.acknowledge', $event) }}">@csrf @method('PATCH')<button class="button-secondary compact-button btn-table">Reconocer</button></form>
                                            <form method="POST" action="{{ route('traceability.alerts.silence', $event) }}">@csrf @method('PATCH')<input type="hidden" name="hours" value="24"><button class="button-secondary compact-button btn-table">Silenciar 24 h</button></form>
                                            <form method="POST" action="{{ route('traceability.alerts.resolve', $event) }}">@csrf @method('PATCH')<button class="button-secondary compact-button btn-table">Resolver</button></form>
                                        </div>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7">No hay alertas con estos filtros.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($events->hasPages())
            <div class="pagination-card surface-card compact-card">{{ $events->links() }}</div>
        @endif

        @if (! empty($filters['client_id']))
            <section class="surface-card compact-card wms-table-panel wms-stock-table-panel wms-stock-rules-panel">
                <div class="wms-table-toolbar">
                    <div>
                        <strong>Reglas del cliente</strong>
                        <span>La prevision es determinista y muestra cuando el historico no es suficiente.</span>
                    </div>
                    <div class="wms-table-totals">
                        <span>{{ $rules->total() }} reglas</span>
                    </div>
                </div>

                <div class="traceability-rule-list wms-stock-rule-list">
                    @forelse ($rules as $rule)
                        @php($prediction = $forecasts[$rule->id])
                        <details class="traceability-rule-row wms-stock-rule-row">
                            <summary>
                                <span>
                                    <strong>{{ $rule->item?->sku }} · {{ $rule->item?->description }}</strong>
                                    <small>{{ $rule->active ? 'Activa' : 'Inactiva' }} · {{ $prediction['reason'] }} · {{ number_format($prediction['available_units'], 0, ',', '.') }} uds</small>
                                </span>
                                <span class="status-chip small-badge badge-compact wms-stock-alert-severity wms-stock-alert-severity--{{ $rule->severity }}">{{ $rule->severity }}</span>
                            </summary>
                            @if ($canManage)
                                <form method="POST" action="{{ route('traceability.alerts.rules.update', $rule) }}" class="traceability-rule-form wms-stock-rule-form">@csrf @method('PUT')<input type="hidden" name="client_id" value="{{ $rule->client_id }}"><input type="hidden" name="item_id" value="{{ $rule->item_id }}"><input type="hidden" name="active" value="0"><label class="toggle-field"><input type="checkbox" name="active" value="1" @checked($rule->active)><span>Activa</span></label><label class="auth-field"><span>Unidades minimas</span><input type="number" name="minimum_units" value="{{ $rule->minimum_units }}" min="0" class="auth-input"></label><label class="auth-field"><span>Pallets minimos</span><input type="number" name="minimum_pallets" value="{{ $rule->minimum_pallets }}" min="0" class="auth-input"></label><label class="auth-field"><span>Cobertura minima</span><input type="number" name="minimum_coverage_days" value="{{ $rule->minimum_coverage_days }}" min="0" class="auth-input"></label><label class="auth-field"><span>Horizonte agotamiento</span><input type="number" name="exhaustion_horizon_days" value="{{ $rule->exhaustion_horizon_days }}" min="0" class="auth-input"></label><label class="auth-field"><span>Stock seguridad</span><input type="number" name="safety_stock_units" value="{{ $rule->safety_stock_units }}" min="0" class="auth-input"></label><label class="auth-field"><span>Lead time</span><input type="number" name="lead_time_days" value="{{ $rule->lead_time_days }}" min="0" class="auth-input"></label><label class="auth-field"><span>Severidad</span><select name="severity" class="auth-input"><option value="warning" @selected($rule->severity === 'warning')>Advertencia</option><option value="critical" @selected($rule->severity === 'critical')>Critica</option></select></label><label class="auth-field"><span>Cooldown minutos</span><input type="number" name="cooldown_minutes" value="{{ $rule->cooldown_minutes }}" min="1" class="auth-input"></label><label class="toggle-field"><input type="checkbox" name="include_blocked_stock" value="1" @checked($rule->include_blocked_stock)><span>Incluir bloqueado</span></label><label class="toggle-field"><input type="checkbox" name="include_obsolete_stock" value="1" @checked($rule->include_obsolete_stock)><span>Incluir obsoleto</span></label><div class="item-form-actions"><button class="button-primary compact-button btn-compact">Guardar regla</button></div></form>
                            @endif
                        </details>
                    @empty
                        <p class="helper-text wms-stock-rule-empty">No hay reglas configuradas para este cliente.</p>
                    @endforelse
                </div>
            </section>

            @if ($rules->hasPages())
                <div class="pagination-card surface-card compact-card">{{ $rules->links() }}</div>
            @endif
        @endif
    </div>
@endsection
