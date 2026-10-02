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

        @foreach($templates as $key => $preset)
            <section class="surface-card deca-action">
                <h2>{{ $preset['title'] }}</h2>
                <p>{{ $preset['goods'] }} · {{ number_format($preset['weight_kg'], 0, ',', '.') }} kg aprox.</p>
                <a href="{{ route('deca.quick.create', $key) }}" class="button-primary">{{ $preset['title'] }} · Elegir vehículo</a>
            </section>
        @endforeach
        <a href="{{ route('deca.create') }}" class="button-secondary">Crear DECA manual</a>

        <a href="{{ route('deca.index') }}" class="button-secondary deca-back">Volver a DECA</a>
    </div>
@endsection
