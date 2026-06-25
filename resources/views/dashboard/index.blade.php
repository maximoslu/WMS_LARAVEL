@extends('layouts.dashboard')

@section('title', 'Dashboard | MAXIMO WMS')

@section('content')
    <section class="ops-dashboard">
        <article class="surface-card ops-dashboard-intro compact-card">
            <div class="app-copy">
                <span class="status-chip small-badge">Dashboard</span>
                <h2 class="ops-page-title">Accesos rapidos</h2>
                <p>Entradas directas a las areas operativas visibles para tu rol.</p>
            </div>

            <div class="ops-kpi-grid kpi-strip">
                <article class="app-stat kpi-card">
                    <strong>Accesos visibles</strong>
                    <span>{{ $visibleModuleCount }} disponibles para tu perfil</span>
                </article>

                <article class="app-stat kpi-card">
                    <strong>Rol activo</strong>
                    <span>{{ $currentRoleName }}</span>
                </article>

                <article class="app-stat kpi-card">
                    <strong>Estado</strong>
                    <span>Navegacion agrupada y permisos activos</span>
                </article>
            </div>
        </article>
    </section>

    <section class="ops-section-grid" aria-label="Secciones operativas">
        @foreach ($navigationSections as $section)
            <article class="surface-card ops-section-card compact-card">
                <div class="ops-section-heading">
                    <strong>{{ $section['title'] }}</strong>
                    <span class="ops-status">{{ count($section['children']) }} accesos</span>
                </div>

                @if ($section['key'] === 'stock')
                    <p class="ops-section-note">Prioridad: stock actual, articulos y ubicaciones.</p>
                @endif

                <div class="ops-action-list">
                    @foreach ($section['children'] as $child)
                        <a href="{{ route($child['route']) }}" class="ops-action-card{{ request()->routeIs(...($child['active_patterns'] ?? [$child['route']])) ? ' is-active' : '' }}">
                            <strong>{{ $child['title'] }}</strong>
                            <div class="ops-action-meta">
                                <span class="ops-status {{ $child['status'] === 'ready' ? 'ops-status--ready' : 'ops-status--placeholder' }}">
                                    {{ $child['status_label'] }}
                                </span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </article>
        @endforeach
    </section>
@endsection
