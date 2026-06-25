@extends('layouts.dashboard')

@section('title', 'Dashboard | MAXIMO WMS')

@section('content')
    <section class="ops-dashboard">
        <article class="surface-card ops-dashboard-intro">
            <div class="app-copy">
                <span class="status-chip">Panel operativo</span>
                <h2 class="app-page-title">Accesos rapidos por seccion</h2>
            </div>

            <div class="ops-kpi-grid">
                <article class="app-stat">
                    <strong>Accesos visibles</strong>
                    <span>{{ $visibleModuleCount }} disponibles para tu perfil</span>
                </article>

                <article class="app-stat">
                    <strong>Rol activo</strong>
                    <span>{{ $currentRoleName }}</span>
                </article>

                <article class="app-stat">
                    <strong>Estado</strong>
                    <span>Jerarquia de permisos y navegacion agrupada activas</span>
                </article>
            </div>
        </article>
    </section>

    <section class="ops-section-grid" aria-label="Secciones operativas">
        @foreach ($navigationSections as $section)
            <article class="surface-card ops-section-card">
                <div class="ops-section-heading">
                    <strong>{{ $section['title'] }}</strong>
                    <span class="ops-status">{{ count($section['children']) }} accesos</span>
                </div>

                @if ($section['key'] === 'stock')
                    <p class="ops-section-note">
                        Prioridad operativa: consulta primero Stock actual y despues el maestro de Articulos.
                    </p>
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
