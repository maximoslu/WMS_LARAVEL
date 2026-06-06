@extends('layouts.dashboard')

@section('title', $module['title'].' | MAXIMO WMS')

@section('content')
    <section class="wms-dashboard-hero">
        <div>
            <span class="wms-badge">{{ $module['tag'] }}</span>
            <h2>{{ $module['title'] }}</h2>
            <p>{{ $module['summary'] }}</p>
        </div>

        <div class="wms-hero-card">
            <strong>Modulo en preparacion</strong>
            <span>Acceso minimo: {{ $module['minimum_role_name'] }}</span>
            <span>Ruta prevista: {{ $module['path'] }}</span>
            <span>La arquitectura ya esta protegida por autenticacion y rol.</span>
        </div>
    </section>

    <section class="wms-dashboard-note">
        <h3>Estado actual</h3>
        <p>Este espacio ya forma parte de la navegacion real del WMS y queda listo para incorporar logica de negocio sin rehacer permisos ni layout.</p>
    </section>
@endsection
