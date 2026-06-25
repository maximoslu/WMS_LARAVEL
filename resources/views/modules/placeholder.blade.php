@extends('layouts.dashboard')

@section('title', $module['title'].' | MAXIMO WMS')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel operativo</a>
        <span>/</span>
        <span>{{ $module['section_title'] }}</span>
        <span>/</span>
        <span>{{ $module['title'] }}</span>
    </nav>

    <section class="placeholder-layout ops-placeholder-layout">
        <article class="placeholder-card surface-card ops-placeholder-main">
            <div class="app-copy">
                <span class="module-tag">{{ $module['tag'] }}</span>
                <h2 class="placeholder-title">{{ $module['title'] }}</h2>
                <p>{{ $module['summary'] }}</p>
            </div>

            <div class="ops-placeholder-actions">
                <a href="{{ route('dashboard') }}" class="button-secondary">Volver al panel</a>

                @if ($module['section_key'] === 'stock' && auth()->user()->canAccessRole(\App\Models\Role::ALMACEN))
                    <a href="{{ route('items.index') }}" class="button-primary">Abrir articulos</a>
                @endif
            </div>
        </article>

        <aside class="placeholder-meta surface-card ops-placeholder-side">
            <span class="placeholder-state">{{ $module['status_label'] }}</span>
            <div>
                <strong>Seccion</strong>
                <span>{{ $module['section_title'] }}</span>
            </div>
            <div>
                <strong>Acceso minimo</strong>
                <span>{{ $module['minimum_role_name'] }}</span>
            </div>
            <div>
                <strong>Ruta</strong>
                <span>{{ $module['path'] }}</span>
            </div>
            <div>
                <strong>Proximo paso funcional</strong>
                <span>{{ $module['next_step'] ?? 'Definir siguientes reglas de negocio para este modulo.' }}</span>
            </div>
        </aside>
    </section>

    <section class="placeholder-grid ops-placeholder-grid" aria-label="Detalles del modulo">
        <article class="placeholder-meta surface-card">
            <strong>Estado actual</strong>
            <p>El acceso ya esta integrado en la navegacion operativa y protegido por rol.</p>
        </article>

        <article class="placeholder-meta surface-card">
            <strong>Siguiente desarrollo</strong>
            <p>{{ $module['next_step'] ?? 'Pendiente de definir en siguientes iteraciones del roadmap.' }}</p>
        </article>

        <article class="placeholder-meta surface-card">
            <strong>Proteccion activa</strong>
            <p>Autenticacion, jerarquia de roles y acceso agrupado por secciones ya estan aplicados.</p>
        </article>
    </section>
@endsection
