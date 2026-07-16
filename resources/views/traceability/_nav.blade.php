<nav class="traceability-nav" aria-label="Secciones de trazabilidad">
    <a href="{{ route('traceability.index') }}" @class(['is-active' => request()->routeIs('traceability.index')])>Resumen</a>
    <a href="{{ route('traceability.movements.index') }}" @class(['is-active' => request()->routeIs('traceability.movements.*')])>Movimientos</a>
    <a href="{{ route('traceability.lots.index') }}" @class(['is-active' => request()->routeIs('traceability.lots.*')])>Lotes</a>
    <a href="{{ route('traceability.alerts.index') }}" @class(['is-active' => request()->routeIs('traceability.alerts.*')])>Alertas</a>
    @if (auth()->user()->canAccessRole(\App\Models\Role::ADMINISTRACION))
        <a href="{{ route('traceability.activity.index') }}" @class(['is-active' => request()->routeIs('traceability.activity.*')])>Actividad</a>
        <a href="{{ route('traceability.audit.index') }}" @class(['is-active' => request()->routeIs('traceability.audit.*')])>Auditoria</a>
        <a href="{{ route('traceability.analytics.index') }}" @class(['is-active' => request()->routeIs('traceability.analytics.*')])>Analitica</a>
        <a href="{{ route('traceability.reports.index') }}" @class(['is-active' => request()->routeIs('traceability.reports.*')])>Informes</a>
    @endif
</nav>
