@extends('layouts.dashboard')

@section('title', 'Nueva solicitud | MAXIMO WMS')
@section('topbar_title', 'Nueva solicitud')

@section('content')
    <nav class="ops-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('dashboard') }}">Panel de control</a>
        <span>/</span>
        <a href="{{ route('bookings.index') }}">{{ $isClient ? 'Solicitudes' : 'Bookings' }}</a>
        <span>/</span>
        <span>{{ $isClient ? 'Nueva solicitud' : 'Crear' }}</span>
    </nav>

    <section class="surface-card ops-page-header page-header-compact compact-card wms-page-hero">
        <div class="ops-page-headline">
            <h2 class="ops-page-title page-title-compact">{{ $isClient ? 'Nueva solicitud' : 'Crear booking' }}</h2>
            <span class="ops-page-meta">La solicitud quedara pendiente de validacion operativa.</span>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-error">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    <section class="surface-card compact-card daily-ops-card">
        @if ($isClient)
            <p class="helper-text">Indica el tipo, la fecha prevista y cualquier detalle util. El equipo interno completara el resto de datos operativos.</p>
        @endif
        @include('bookings._form')
    </section>
@endsection
