@extends('layouts.dashboard')

@section('title', 'Trazabilidad | MAXIMO WMS')
@section('topbar_title', 'Trazabilidad')

@section('content')
    <x-breadcrumbs :items="[['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'], ['label' => 'Gestion'], ['label' => 'Trazabilidad']]" />
    @include('traceability._nav')

    <section class="surface-card ops-page-header page-header-compact compact-card traceability-hero">
        <div class="app-copy">
            <span class="status-chip small-badge badge-compact">Ultimos {{ $summary['period_days'] }} dias</span>
            <h1 class="ops-page-title page-title-compact">Control operativo verificable</h1>
            <p>Movimientos, lotes, acciones y alertas separados por su naturaleza y unidos mediante correlacion.</p>
        </div>
    </section>

    <section class="traceability-kpi-grid">
        <a href="{{ route('traceability.movements.index', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]) }}" class="surface-card kpi-card kpi-compact">
            <span>Movimientos hoy</span><strong>{{ number_format($summary['movements_today'], 0, ',', '.') }}</strong>
        </a>
        <a href="{{ route('traceability.movements.index') }}" class="surface-card kpi-card kpi-compact">
            <span>Entradas del periodo</span><strong>{{ number_format($summary['entries_period'], 0, ',', '.') }} uds</strong>
        </a>
        <a href="{{ route('traceability.movements.index') }}" class="surface-card kpi-card kpi-compact">
            <span>Salidas del periodo</span><strong>{{ number_format($summary['dispatches_period'], 0, ',', '.') }} uds</strong>
        </a>
        <a href="{{ route('traceability.alerts.index') }}" class="surface-card kpi-card kpi-compact">
            <span>Alertas activas</span><strong>{{ number_format($summary['active_alerts'], 0, ',', '.') }}</strong>
        </a>
        <a href="{{ route('traceability.lots.index') }}" class="surface-card kpi-card kpi-compact">
            <span>Lotes incompletos</span><strong>{{ number_format($summary['incomplete_lots'], 0, ',', '.') }}</strong>
        </a>
        @if ($canAdminister)
            <a href="{{ route('traceability.activity.index') }}" class="surface-card kpi-card kpi-compact">
                <span>Usuarios recientes</span><strong>{{ number_format($summary['recent_users'], 0, ',', '.') }}</strong>
            </a>
        @endif
    </section>

    <div class="traceability-content-grid">
        <section class="surface-card stock-table-shell compact-card">
            <div class="item-form-header"><div class="app-copy"><h2 class="ops-page-title page-title-compact">Mercancia con mas movimiento</h2><p>Volumen absoluto del periodo.</p></div></div>
            <div class="data-table-wrap">
                <table class="data-table table-compact">
                    <thead><tr><th>Cliente</th><th>SKU</th><th>Articulo</th><th class="numeric-cell">Unidades</th></tr></thead>
                    <tbody>
                        @forelse ($summary['top_items'] as $row)
                            <tr><td>{{ $row->client_name }}</td><td><strong>{{ $row->sku }}</strong></td><td>{{ $row->description }}</td><td class="numeric-cell">{{ number_format($row->moved_units, 0, ',', '.') }}</td></tr>
                        @empty
                            <tr><td colspan="4">Todavia no hay movimientos registrados en el ledger.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($canAdminister)
            <section class="surface-card stock-table-shell compact-card">
                <div class="item-form-header"><div class="app-copy"><h2 class="ops-page-title page-title-compact">Ultimas acciones sensibles</h2><p>Auditoria empresarial, no navegacion.</p></div></div>
                <div class="traceability-event-list">
                    @forelse ($summary['latest_actions'] as $log)
                        <a href="{{ route('traceability.audit.index', ['correlation_id' => $log->correlation_id]) }}">
                            <strong>{{ $log->description }}</strong><span>{{ $log->user_name ?: 'Sistema' }} · {{ $log->occurred_at?->format('d/m/Y H:i') }}</span>
                        </a>
                    @empty
                        <p class="helper-text">Sin acciones auditadas.</p>
                    @endforelse
                </div>
            </section>
        @endif
    </div>
@endsection
