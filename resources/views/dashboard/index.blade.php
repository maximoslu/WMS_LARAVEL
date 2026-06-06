@extends('layouts.dashboard')

@section('title', 'Dashboard | MAXIMO WMS')

@section('content')
    <section class="wms-dashboard-hero">
        <div>
            <span class="wms-badge">Panel inicial</span>
            <h2>Bienvenido, {{ auth()->user()->name }}</h2>
            <p>El flujo de acceso ya esta operativo. Desde aqui creceran los modulos de recepcion, stock, pedidos y trazabilidad.</p>
        </div>

        <div class="wms-hero-card">
            <strong>Estado del entorno</strong>
            <span>Autenticacion web activa</span>
            <span>Dashboard protegido por middleware</span>
            <span>Recuperacion de contrasena preparada</span>
        </div>
    </section>

    <section class="wms-dashboard-grid">
        <article class="wms-stat-card">
            <span class="wms-stat-label">Accesos</span>
            <strong>Operativo</strong>
            <p>Login con email y contrasena sobre el guard web nativo de Laravel.</p>
        </article>

        <article class="wms-stat-card">
            <span class="wms-stat-label">Clientes iniciales</span>
            <strong>Friesland / Edelvives</strong>
            <p>Base visual preparada para evolucionar hacia un entorno multicliente.</p>
        </article>

        <article class="wms-stat-card">
            <span class="wms-stat-label">Solicitud de acceso</span>
            <strong>Pendiente de revision</strong>
            <p>Captura simple de peticiones de alta para futura aprobacion operativa.</p>
        </article>
    </section>

    <section class="wms-dashboard-note">
        <h3>Siguiente fase recomendada</h3>
        <p>Conectar roles, permisos y primera navegacion funcional del WMS sin romper la simplicidad del monolito.</p>
    </section>
@endsection
