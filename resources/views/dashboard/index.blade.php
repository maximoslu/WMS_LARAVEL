@extends('layouts.dashboard')

@section('title', 'Dashboard | MAXIMO WMS')

@section('content')
    <section class="app-overview">
        <article class="app-overview-card surface-card">
            <div class="app-copy">
                <span class="status-chip">Panel operativo</span>
                <h2 class="app-page-title">Bienvenido, {{ auth()->user()->name }}</h2>
                <p>Accede a los modulos operativos habilitados para tu perfil y trabaja sobre una interfaz comun para almacen, administracion y cliente.</p>
            </div>

            <div class="app-stat-grid">
                <article class="app-stat">
                    <strong>Modulos visibles</strong>
                    <span>{{ count($navigationItems) }} disponibles para tu perfil</span>
                </article>

                <article class="app-stat">
                    <strong>Rol activo</strong>
                    <span>{{ $currentRoleName }}</span>
                </article>

                <article class="app-stat">
                    <strong>Estado de acceso</strong>
                    <span>Sesion autenticada y permisos aplicados</span>
                </article>
            </div>
        </article>

        <aside class="app-overview-card app-overview-card--stacked surface-card">
            <div class="app-copy">
                <span class="status-chip">Estado del entorno</span>
                <h2 class="app-page-title">Base operativa preparada</h2>
                <p>Cabecera, navegacion y modulos comparten el mismo sistema visual para evolucionar sin rehacer la experiencia de usuario.</p>
            </div>

            <div class="app-stat-grid">
                <article class="app-stat">
                    <strong>Navegacion</strong>
                    <span>Acceso tactil claro en movil, tablet y escritorio</span>
                </article>

                <article class="app-stat">
                    <strong>Seguridad</strong>
                    <span>Autenticacion y jerarquia de roles integradas</span>
                </article>

                <article class="app-stat">
                    <strong>Siguiente fase</strong>
                    <span>Desarrollo progresivo de logica por modulo</span>
                </article>
            </div>
        </aside>
    </section>

    <section class="module-grid" aria-label="Modulos disponibles">
        @foreach ($navigationItems as $item)
            <a href="{{ route($item['route']) }}" class="module-card surface-card">
                <div class="module-card-header">
                    <span class="module-tag">{{ $item['tag'] }}</span>
                    <span class="status-chip">Disponible</span>
                </div>

                <div class="app-copy">
                    <strong>{{ $item['title'] }}</strong>
                    <p>{{ $item['summary'] }}</p>
                </div>

                <div class="module-card-footer">
                    <span class="module-link">Abrir modulo</span>
                    <span class="module-path">{{ $item['path'] }}</span>
                </div>
            </a>
        @endforeach
    </section>

    <section class="app-callout surface-card">
        <h3>Roadmap inmediato</h3>
        <p>La base visual queda lista para incorporar procesos reales de stock, solicitudes, entradas, salidas y administracion sin rehacer login, cabecera, navegacion ni placeholders.</p>
    </section>
@endsection
