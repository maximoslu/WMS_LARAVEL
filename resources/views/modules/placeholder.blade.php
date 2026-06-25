@extends('layouts.dashboard')

@section('title', $module['title'].' | MAXIMO WMS')

@section('content')
    <section class="placeholder-layout">
        <article class="placeholder-card surface-card">
            <div class="app-copy">
                <span class="module-tag">{{ $module['tag'] }}</span>
                <h2 class="placeholder-title">{{ $module['title'] }}</h2>
                <p>{{ $module['summary'] }}</p>
                <a href="{{ route('dashboard') }}" class="back-link">Volver al dashboard</a>
            </div>
        </article>

        <aside class="placeholder-meta surface-card">
            <span class="placeholder-state">Modulo en preparacion</span>
            <div>
                <strong>Acceso minimo</strong>
                <span>{{ $module['minimum_role_name'] }}</span>
            </div>
            <div>
                <strong>Ruta prevista</strong>
                <span>{{ $module['path'] }}</span>
            </div>
            <div>
                <strong>Proteccion activa</strong>
                <span>Autenticacion y permisos por rol ya aplicados</span>
            </div>
        </aside>
    </section>

    <section class="placeholder-grid" aria-label="Detalles del modulo">
        @if ($module['key'] === 'stock' && auth()->user()->canAccessRole(\App\Models\Role::ALMACEN))
            <article class="placeholder-meta surface-card">
                <strong>Acceso ya disponible</strong>
                <p>El maestro de articulos ya esta operativo para almacen, administracion y superadmin.</p>
                <a href="{{ route('items.index') }}" class="back-link">Abrir articulos</a>
            </article>
        @endif

        <article class="placeholder-meta surface-card">
            <strong>Estado actual</strong>
            <p>La estructura visual y la ruta del modulo ya forman parte del flujo real del WMS.</p>
        </article>

        <article class="placeholder-meta surface-card">
            <strong>Siguiente desarrollo</strong>
            <p>Queda listo para incorporar reglas de negocio, formularios y listados sin rehacer la navegacion.</p>
        </article>

        <article class="placeholder-meta surface-card">
            <strong>Contexto funcional</strong>
            <p>El acceso a este espacio respeta la jerarquia existente entre superadmin, administracion, almacen y cliente.</p>
        </article>
    </section>
@endsection
