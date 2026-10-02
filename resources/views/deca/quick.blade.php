@extends('layouts.dashboard')

@section('title', 'DECA rápido | MAXIMO WMS')
@section('topbar_title', 'DECA rápido')

@section('content')
    <x-breadcrumbs :items="[
        ['label' => 'Panel de control', 'href' => route('dashboard'), 'icon' => 'dashboard'],
        ['label' => 'DECA', 'href' => route('deca.index')],
        ['label' => 'DECA rápido'],
    ]" />

    <div class="deca-home deca-form-page">
        <section class="surface-card deca-intro" aria-labelledby="deca-quick-title">
            <span class="module-tag">Transportes habituales</span>
            <h1 id="deca-quick-title">DECA rápido</h1>
            <p>Aquí encontrarás tus servicios habituales con el origen, el destino y la carga ya preparados.</p>
        </section>

        <section class="surface-card deca-action" aria-labelledby="deca-quick-empty-title">
            <h2 id="deca-quick-empty-title">Todavía no hay DECA rápidos configurados</h2>
            <p>Cuando estén disponibles, aparecerá un botón para elegir cada transporte. Mientras tanto, puedes crear un DECA manual.</p>
            <a href="{{ route('deca.create') }}" class="button-primary">Crear DECA manual</a>
        </section>

        <a href="{{ route('deca.index') }}" class="button-secondary deca-back">Volver a DECA</a>
    </div>
@endsection
