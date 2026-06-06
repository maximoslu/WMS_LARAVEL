@extends('layouts.dashboard')

@section('title', 'Dashboard | MAXIMO WMS')

@section('content')
    <section class="wms-dashboard-hero">
        <div>
            <span class="wms-badge">Panel inicial</span>
            <h2>Bienvenido, {{ auth()->user()->name }}</h2>
            <p>Accede a los modulos operativos habilitados para tu perfil y utiliza MAXIMO WMS desde movil, tablet o escritorio sin cambiar de flujo.</p>
        </div>

        <div class="wms-hero-card">
            <strong>Estado del acceso</strong>
            <span>Rol actual: {{ $currentRoleName }}</span>
            <span>Autenticacion y dashboard protegidos</span>
            <span>Jerarquia de permisos activa por nivel de rol</span>
        </div>
    </section>

    <section class="wms-dashboard-grid wms-dashboard-grid-modules">
        @foreach ($navigationItems as $item)
            <a href="{{ route($item['route']) }}" class="wms-module-card">
                <span class="wms-stat-label">{{ $item['tag'] }}</span>
                <strong>{{ $item['title'] }}</strong>
                <p>{{ $item['summary'] }}</p>
                <span class="wms-module-link">Abrir modulo</span>
            </a>
        @endforeach
    </section>

    <section class="wms-dashboard-note">
        <h3>Siguiente fase recomendada</h3>
        <p>Desarrollar cada modulo sobre estas rutas protegidas, reutilizando la jerarquia `superadmin > administracion > almacen > cliente` ya integrada en la aplicacion.</p>
    </section>
@endsection
